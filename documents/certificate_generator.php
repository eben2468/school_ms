<?php
session_start();
require_once '../config/database.php';

// Check if user is logged in and has appropriate permissions
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['super_admin', 'school_admin', 'principal', 'teacher'])) {
    header("Location: ../auth/login.php");
    exit();
}

$user_id = $_SESSION['user_id'];
$role = $_SESSION['role'];
$user_name = $_SESSION['name'];

// Handle certificate generation
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['generate_certificate'])) {
    $student_id = $_POST['student_id'];
    $certificate_type = $_POST['certificate_type'];
    $certificate_title = $_POST['certificate_title'];
    $description = $_POST['description'] ?? '';
    $issue_date = $_POST['issue_date'];
    $academic_year = $_POST['academic_year'] ?? date('Y') . '-' . (date('Y') + 1);
    
    // Get student information
    $student_query = "SELECT u.name, u.email, sp.student_id as student_number, c.name as class_name
                     FROM users u 
                     LEFT JOIN student_profiles sp ON u.id = sp.user_id
                     LEFT JOIN student_classes sc ON u.id = sc.student_id AND sc.status = 'active'
                     LEFT JOIN classes c ON sc.class_id = c.id
                     WHERE u.id = ? AND u.role = 'student'";
    $stmt = $conn->prepare($student_query);
    $stmt->bind_param("i", $student_id);
    $stmt->execute();
    $student = $stmt->get_result()->fetch_assoc();
    
    if ($student) {
        // Generate certificate content
        $certificate_content = generateCertificateHTML($student, $certificate_type, $certificate_title, $description, $issue_date, $academic_year);
        
        // Save certificate to database
        $insert_query = "INSERT INTO documents (title, description, file_path, file_type, file_size, uploaded_by, document_type, access_level, related_user_id, academic_year)
                        VALUES (?, ?, ?, 'html', ?, ?, 'certificate', 'students', ?, ?)";
        
        $file_name = 'certificate_' . $student_id . '_' . time() . '.html';
        $file_path = '../uploads/certificates/' . $file_name;
        
        // Create directory if it doesn't exist
        if (!is_dir('../uploads/certificates/')) {
            mkdir('../uploads/certificates/', 0755, true);
        }
        
        // Save certificate HTML file
        file_put_contents($file_path, $certificate_content);
        $file_size = filesize($file_path);
        
        $stmt = $conn->prepare($insert_query);
        $stmt->bind_param("sssiisis", $certificate_title, $description, $file_name, $file_size, $user_id, $student_id, $academic_year);
        
        if ($stmt->execute()) {
            $success_message = "Certificate generated successfully for " . htmlspecialchars($student['name']);
            $generated_certificate_path = $file_path;
        } else {
            $error_message = "Failed to save certificate to database.";
        }
    } else {
        $error_message = "Student not found.";
    }
}

// Get students for dropdown
$students = [];
$student_query = "SELECT u.id, u.name, sp.student_id as student_number, c.name as class_name
                 FROM users u 
                 LEFT JOIN student_profiles sp ON u.id = sp.user_id
                 LEFT JOIN student_classes sc ON u.id = sc.student_id AND sc.status = 'active'
                 LEFT JOIN classes c ON sc.class_id = c.id
                 WHERE u.role = 'student' AND u.status = 'active'
                 ORDER BY u.name";
$stmt = $conn->prepare($student_query);
$stmt->execute();
$students = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

function generateCertificateHTML($student, $type, $title, $description, $issue_date, $academic_year) {
    $html = '
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Certificate - ' . htmlspecialchars($title) . '</title>
        <style>
            body {
                font-family: "Times New Roman", serif;
                margin: 0;
                padding: 40px;
                background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
                min-height: 100vh;
                display: flex;
                align-items: center;
                justify-content: center;
            }
            .certificate {
                background: white;
                padding: 60px;
                border-radius: 20px;
                box-shadow: 0 20px 40px rgba(0,0,0,0.1);
                max-width: 800px;
                width: 100%;
                text-align: center;
                border: 8px solid #f0f0f0;
                position: relative;
            }
            .certificate::before {
                content: "";
                position: absolute;
                top: 20px;
                left: 20px;
                right: 20px;
                bottom: 20px;
                border: 3px solid #667eea;
                border-radius: 10px;
            }
            .header {
                margin-bottom: 40px;
            }
            .school-name {
                font-size: 36px;
                font-weight: bold;
                color: #333;
                margin-bottom: 10px;
                text-transform: uppercase;
                letter-spacing: 2px;
            }
            .certificate-title {
                font-size: 28px;
                color: #667eea;
                margin-bottom: 30px;
                text-transform: uppercase;
                letter-spacing: 1px;
            }
            .content {
                margin: 40px 0;
                line-height: 1.8;
            }
            .student-name {
                font-size: 32px;
                font-weight: bold;
                color: #333;
                margin: 20px 0;
                text-decoration: underline;
                text-decoration-color: #667eea;
            }
            .description {
                font-size: 18px;
                color: #555;
                margin: 20px 0;
                line-height: 1.6;
            }
            .footer {
                margin-top: 60px;
                display: flex;
                justify-content: space-between;
                align-items: end;
            }
            .signature {
                text-align: center;
                border-top: 2px solid #333;
                padding-top: 10px;
                width: 200px;
            }
            .date {
                font-size: 16px;
                color: #666;
            }
            .seal {
                position: absolute;
                top: 30px;
                right: 30px;
                width: 80px;
                height: 80px;
                border: 3px solid #667eea;
                border-radius: 50%;
                display: flex;
                align-items: center;
                justify-content: center;
                background: rgba(102, 126, 234, 0.1);
                font-weight: bold;
                color: #667eea;
                font-size: 12px;
            }
        </style>
    </head>
    <body>
        <div class="certificate">
            <div class="seal">OFFICIAL<br>SEAL</div>
            
            <div class="header">
                <div class="school-name">Greenwood Academy</div>
                <div style="font-size: 16px; color: #666; margin-bottom: 20px;">Excellence in Education</div>
                <div class="certificate-title">' . htmlspecialchars($title) . '</div>
            </div>
            
            <div class="content">
                <p style="font-size: 20px; margin-bottom: 30px;">This is to certify that</p>
                
                <div class="student-name">' . htmlspecialchars($student['name']) . '</div>
                
                <p style="font-size: 18px; margin: 30px 0;">
                    Student ID: ' . htmlspecialchars($student['student_number'] ?? 'N/A') . '<br>
                    Class: ' . htmlspecialchars($student['class_name'] ?? 'N/A') . '
                </p>
                
                <div class="description">' . nl2br(htmlspecialchars($description)) . '</div>
                
                <p style="font-size: 16px; margin-top: 30px; color: #666;">
                    Academic Year: ' . htmlspecialchars($academic_year) . '
                </p>
            </div>
            
            <div class="footer">
                <div class="signature">
                    <div style="font-weight: bold;">Principal</div>
                    <div style="font-size: 14px; color: #666;">Greenwood Academy</div>
                </div>
                
                <div class="date">
                    <strong>Date of Issue:</strong><br>
                    ' . date('F j, Y', strtotime($issue_date)) . '
                </div>
                
                <div class="signature">
                    <div style="font-weight: bold;">Registrar</div>
                    <div style="font-size: 14px; color: #666;">Academic Office</div>
                </div>
            </div>
        </div>
    </body>
    </html>';
    
    return $html;
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Certificate Generator - Greenwood Academy</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <script>
        tailwind.config = {
            darkMode: 'class',
            theme: {
                extend: {
                    colors: {
                        primary: {"50":"#eff6ff","100":"#dbeafe","200":"#bfdbfe","300":"#93c5fd","400":"#60a5fa","500":"#3b82f6","600":"#2563eb","700":"#1d4ed8","800":"#1e40af","900":"#1e3a8a","950":"#172554"}
                    }
                }
            }
        }
    </script>
</head>
<body class="bg-gray-50 dark:bg-gray-900">
    <!-- Header -->
    <?php include '../includes/header.php'; ?>

    <!-- Sidebar -->
    <?php include '../includes/sidebar.php'; ?>

    <!-- Main Content -->
    <div class="lg:ml-64 pt-16">
        <div class="max-w-4xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
            <!-- Page Header -->
            <div class="mb-8">
                <div class="flex items-center justify-between">
                    <div>
                        <h1 class="text-3xl font-bold text-gray-900 dark:text-white">Certificate Generator</h1>
                        <p class="text-gray-600 dark:text-gray-400 mt-2">Generate official certificates for students</p>
                    </div>
                    <a href="index.php" class="bg-gray-500 hover:bg-gray-600 text-white px-4 py-2 rounded-lg transition-colors">
                        <i class="fas fa-arrow-left mr-2"></i>Back
                    </a>
                </div>
            </div>

            <!-- Success/Error Messages -->
            <?php if (isset($success_message)): ?>
            <div class="bg-green-100 border border-green-400 text-green-700 px-4 py-3 rounded mb-6">
                <div class="flex items-center justify-between">
                    <div class="flex items-center">
                        <i class="fas fa-check-circle mr-2"></i>
                        <?php echo $success_message; ?>
                    </div>
                    <?php if (isset($generated_certificate_path)): ?>
                    <a href="<?php echo $generated_certificate_path; ?>" target="_blank" class="bg-green-600 text-white px-3 py-1 rounded text-sm hover:bg-green-700">
                        <i class="fas fa-eye mr-1"></i>View Certificate
                    </a>
                    <?php endif; ?>
                </div>
            </div>
            <?php endif; ?>

            <?php if (isset($error_message)): ?>
            <div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded mb-6">
                <i class="fas fa-exclamation-circle mr-2"></i><?php echo $error_message; ?>
            </div>
            <?php endif; ?>

            <!-- Certificate Generation Form -->
            <div class="bg-white dark:bg-gray-800 rounded-xl shadow-lg">
                <div class="p-6 border-b border-gray-200 dark:border-gray-700">
                    <h2 class="text-xl font-semibold text-gray-900 dark:text-white">Generate New Certificate</h2>
                </div>
                
                <form method="POST" class="p-6 space-y-6">
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                        <div>
                            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                                Select Student *
                            </label>
                            <select name="student_id" required class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg focus:ring-2 focus:ring-blue-500 dark:bg-gray-700 dark:text-white">
                                <option value="">Choose a student</option>
                                <?php foreach ($students as $student): ?>
                                <option value="<?php echo $student['id']; ?>">
                                    <?php echo htmlspecialchars($student['name']); ?>
                                    <?php if ($student['student_number']): ?>
                                    (<?php echo htmlspecialchars($student['student_number']); ?>)
                                    <?php endif; ?>
                                    <?php if ($student['class_name']): ?>
                                    - <?php echo htmlspecialchars($student['class_name']); ?>
                                    <?php endif; ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <div>
                            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                                Certificate Type *
                            </label>
                            <select name="certificate_type" required class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg focus:ring-2 focus:ring-blue-500 dark:bg-gray-700 dark:text-white">
                                <option value="">Select type</option>
                                <option value="completion">Course Completion</option>
                                <option value="achievement">Academic Achievement</option>
                                <option value="participation">Participation</option>
                                <option value="excellence">Excellence Award</option>
                                <option value="graduation">Graduation</option>
                                <option value="conduct">Good Conduct</option>
                                <option value="attendance">Perfect Attendance</option>
                            </select>
                        </div>
                    </div>
                    
                    <div>
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                            Certificate Title *
                        </label>
                        <input type="text" name="certificate_title" required 
                               class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg focus:ring-2 focus:ring-blue-500 dark:bg-gray-700 dark:text-white" 
                               placeholder="e.g., Certificate of Academic Excellence">
                    </div>
                    
                    <div>
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                            Description/Achievement Details
                        </label>
                        <textarea name="description" rows="4" 
                                  class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg focus:ring-2 focus:ring-blue-500 dark:bg-gray-700 dark:text-white" 
                                  placeholder="Describe the achievement or reason for the certificate..."></textarea>
                    </div>
                    
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                        <div>
                            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                                Issue Date *
                            </label>
                            <input type="date" name="issue_date" value="<?php echo date('Y-m-d'); ?>" required 
                                   class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg focus:ring-2 focus:ring-blue-500 dark:bg-gray-700 dark:text-white">
                        </div>
                        
                        <div>
                            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                                Academic Year
                            </label>
                            <input type="text" name="academic_year" value="<?php echo date('Y') . '-' . (date('Y') + 1); ?>" 
                                   class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg focus:ring-2 focus:ring-blue-500 dark:bg-gray-700 dark:text-white" 
                                   placeholder="e.g., 2024-2025">
                        </div>
                    </div>
                    
                    <div class="flex justify-end space-x-3 pt-6 border-t border-gray-200 dark:border-gray-700">
                        <a href="index.php" class="px-4 py-2 text-gray-600 dark:text-gray-400 hover:text-gray-800 dark:hover:text-gray-200">
                            Cancel
                        </a>
                        <button type="submit" name="generate_certificate" class="bg-blue-600 text-white px-6 py-2 rounded-lg hover:bg-blue-700 transition-colors">
                            <i class="fas fa-certificate mr-2"></i>Generate Certificate
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Footer -->
    <?php include '../includes/footer.php'; ?>
</body>
</html>
