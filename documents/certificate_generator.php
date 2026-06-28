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
    $template_id = !empty($_POST['template_id']) ? $_POST['template_id'] : 1;
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
        $certificate_content = generateCertificateHTML($student, $certificate_type, $certificate_title, $description, $issue_date, $academic_year, $template_id);
        
        $file_name = 'certificate_' . $student_id . '_' . time() . '.html';
        $file_path = '../uploads/certificates/' . $file_name;
        
        // Create directory if it doesn't exist
        if (!is_dir('../uploads/certificates/')) {
            mkdir('../uploads/certificates/', 0755, true);
        }
        
        // Save certificate HTML file
        file_put_contents($file_path, $certificate_content);
        $file_size = filesize($file_path);
        
        // Save certificate to documents table
        $insert_query = "INSERT INTO documents (title, description, file_path, file_type, file_size, uploaded_by, document_type, access_level, related_user_id, academic_year)
                        VALUES (?, ?, ?, 'html', ?, ?, 'certificate', 'students', ?, ?)";
        $stmt = $conn->prepare($insert_query);
        $stmt->bind_param("sssiiis", $certificate_title, $description, $file_name, $file_size, $user_id, $student_id, $academic_year);
        
        if ($stmt->execute()) {
            // Generate unique numbers
            $certificate_number = 'CERT-' . date('Y') . '-' . str_pad(rand(1, 99999), 5, '0', STR_PAD_LEFT);
            $verification_code = 'VC-' . strtoupper(substr(md5(uniqid(rand(), true)), 0, 8));
            $db_cert_file_path = 'uploads/certificates/' . $file_name;
            $qr_code_url = "https://api.qrserver.com/v1/create-qr-code/?size=150x150&data=" . urlencode($verification_code);
            $cert_data_json = json_encode(['academic_year' => $academic_year, 'certificate_type' => $certificate_type]);
            
            // Insert into generated_certificates table
            $insert_cert_query = "INSERT INTO generated_certificates (template_id, student_id, certificate_number, title, description, issue_date, certificate_data, file_path, qr_code_path, verification_code, issued_by, status)
                                  VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'issued')";
            $cert_stmt = $conn->prepare($insert_cert_query);
            $cert_stmt->bind_param("iissssssssi", $template_id, $student_id, $certificate_number, $certificate_title, $description, $issue_date, $cert_data_json, $db_cert_file_path, $qr_code_url, $verification_code, $user_id);
            
            if ($cert_stmt->execute()) {
                $success_message = "Certificate generated successfully for " . htmlspecialchars($student['name']);
                $generated_certificate_id = $cert_stmt->insert_id;
            } else {
                $error_message = "Document saved, but failed to save generated certificate details: " . $conn->error;
            }
        } else {
            $error_message = "Failed to save certificate document: " . $conn->error;
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

// Get active templates for dropdown
$templates = [];
$templates_query = "SELECT id, name FROM certificate_templates WHERE is_active = 1 ORDER BY name";
$tmpl_stmt = $conn->prepare($templates_query);
$tmpl_stmt->execute();
$templates = $tmpl_stmt->get_result()->fetch_all(MYSQLI_ASSOC);

function generateCertificateHTML($student, $type, $title, $description, $issue_date, $academic_year, $template_id = 1) {
    global $conn;
    
    // Fetch school name from settings
    $school_name = 'Greenwood Academy';
    $school_query = "SELECT school_name FROM school_settings LIMIT 1";
    if ($school_stmt = $conn->query($school_query)) {
        if ($school_row = $school_stmt->fetch_assoc()) {
            if (!empty($school_row['school_name'])) {
                $school_name = $school_row['school_name'];
            }
        }
    }
    
    // Fetch school motto from academic settings
    $school_motto = 'Excellence in Character and Knowledge';
    $motto_query = "SELECT setting_value FROM academic_settings WHERE setting_key = 'school_motto' LIMIT 1";
    if ($motto_stmt = $conn->query($motto_query)) {
        if ($motto_row = $motto_stmt->fetch_assoc()) {
            if (!empty($motto_row['setting_value'])) {
                $school_motto = $motto_row['setting_value'];
            }
        }
    }

    // Institutional signatures (embedded when enabled in Settings).
    require_once __DIR__ . '/../includes/signature_helper.php';
    $cert_head_sig = getSchoolSignature('headmaster');
    $cert_reg_sig  = getSchoolSignature('registrar');
    $cert_head_img = signatureImg($cert_head_sig['url'], 44);
    $cert_reg_img  = signatureImg($cert_reg_sig['url'], 44);
    $cert_head_name = $cert_head_sig['name'] ? htmlspecialchars($cert_head_sig['name']) : 'Headmaster/Headmistress';
    $cert_reg_name  = $cert_reg_sig['name'] ? htmlspecialchars($cert_reg_sig['name']) : 'Registrar';

    if ($template_id == 2) {
        $ribbon_text = 'OF ' . strtoupper($type);
        if ($type === 'conduct') {
            $ribbon_text = 'OF GOOD CONDUCT';
        } else if ($type === 'attendance') {
            $ribbon_text = 'OF PERFECT ATTENDANCE';
        } else if ($type === 'achievement') {
            $ribbon_text = 'OF ACHIEVEMENT';
        } else if ($type === 'excellence') {
            $ribbon_text = 'OF EXCELLENCE';
        } else if ($type === 'completion') {
            $ribbon_text = 'OF COMPLETION';
        } else if ($type === 'participation') {
            $ribbon_text = 'OF PARTICIPATION';
        } else if ($type === 'graduation') {
            $ribbon_text = 'OF GRADUATION';
        }

        $html = '
        <!DOCTYPE html>
        <html lang="en">
        <head>
            <meta charset="UTF-8">
            <meta name="viewport" content="width=device-width, initial-scale=1.0">
            <title>Certificate - ' . htmlspecialchars($title) . '</title>
            <style>
                @import url(\'https://fonts.googleapis.com/css2?family=Great+Vibes&family=Montserrat:wght@400;500;600;700&family=Playfair+Display:ital,wght@0,600;0,700;1,400&display=swap\');
                
                :root {
                    /* MANUAL ADJUSTABLE COLORS - Edit these to change the school theme */
                    --cert-primary: #0A2E5C;        /* Dark Blue Wave / Primary Brand */
                    --cert-primary-dark: #051C3B;   /* Dark Navy shading */
                    --cert-secondary: #D4AF37;      /* Primary Gold Accent */
                    --cert-accent: #AA7C11;         /* Darker Gold Accent / Shadows */
                    --cert-accent-light: #FDF0CD;   /* Highlight Gold */
                    --cert-bg: #F9FAFB;             /* Certificate Background */
                    --cert-text: #1F2937;           /* Main text color */
                    --cert-name-color: #0A2E5C;     /* Student name color */
                }

                body {
                    margin: 0;
                    padding: 85px 0 25px 0; /* Add top padding to prevent action bar overlap */
                    background: #8A8A8A; /* Gray background from the reference image */
                    min-height: calc(100vh - 110px);
                    display: flex;
                    align-items: center;
                    justify-content: center;
                    font-family: \'Montserrat\', sans-serif;
                    box-sizing: border-box;
                }

                .certificate-container {
                    position: relative;
                    width: 1000px;
                    height: 700px;
                    background: var(--cert-bg);
                    box-sizing: border-box;
                    padding: 45px 140px;
                    display: flex;
                    flex-direction: column;
                    justify-content: space-between;
                    align-items: center;
                    overflow: hidden;
                    box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.5);
                }

                .certificate-content {
                    position: relative;
                    z-index: 2;
                    width: 100%;
                    height: 100%;
                    display: flex;
                    flex-direction: column;
                    justify-content: space-between;
                    align-items: center;
                    text-align: center;
                    box-sizing: border-box;
                }

                .cert-header {
                    margin-top: 5px;
                    display: flex;
                    flex-direction: column;
                    align-items: center;
                }

                .school-name {
                    font-size: 24px;
                    font-weight: 700;
                    color: var(--cert-primary);
                    text-transform: uppercase;
                    letter-spacing: 4px;
                    margin: 0 0 4px 0;
                    font-family: \'Montserrat\', sans-serif;
                }

                .school-motto {
                    font-size: 11px;
                    font-style: italic;
                    color: #4B5563;
                    letter-spacing: 2px;
                    margin: 0 0 15px 0;
                    font-weight: 500;
                    text-transform: uppercase;
                    font-family: \'Montserrat\', sans-serif;
                }

                .cert-main-title {
                    font-family: \'Playfair Display\', serif;
                    font-size: 54px;
                    font-weight: 700;
                    color: var(--cert-primary);
                    margin: 0;
                    letter-spacing: 6px;
                    text-transform: uppercase;
                    line-height: 1.1;
                }

                .cert-presentation {
                    font-size: 15px;
                    color: var(--cert-text);
                    margin: 25px 0 5px 0;
                    font-weight: 700;
                    letter-spacing: 1px;
                }

                .cert-student-name-container {
                    position: relative;
                    display: inline-block;
                    margin: 5px 0;
                }

                .cert-student-name {
                    font-family: \'Great Vibes\', cursive;
                    font-size: 76px;
                    color: var(--cert-name-color);
                    margin: 0;
                    font-weight: 400;
                    line-height: 1.1;
                    padding: 0 50px;
                }

                .cert-name-underline {
                    width: 580px;
                    height: 2px;
                    background-color: var(--cert-primary);
                    margin: 8px auto 0 auto;
                }

                .cert-description {
                    font-size: 14px;
                    line-height: 1.8;
                    max-width: 620px;
                    margin: 15px auto 5px auto;
                    color: #4B5563;
                    font-weight: 400;
                }

                .cert-footer {
                    width: 100%;
                    display: flex;
                    justify-content: space-between;
                    align-items: flex-end;
                    margin-top: 15px;
                    padding: 0 20px;
                    box-sizing: border-box;
                }

                .signature-block {
                    width: 180px;
                    text-align: center;
                }

                .signature-line {
                    border-top: 1.5px solid var(--cert-primary);
                    width: 100%;
                    margin-bottom: 8px;
                }

                .signature-title {
                    font-size: 11px;
                    text-transform: uppercase;
                    letter-spacing: 2px;
                    font-weight: 700;
                    color: var(--cert-primary);
                }

                .badge-block {
                    margin-bottom: -15px;
                }

                @page {
                    size: A4 landscape;
                    margin: 0;
                }

                @media print {
                    .no-print {
                        display: none !important;
                    }
                    body {
                        background: none;
                        padding: 0 !important;
                        margin: 0;
                    }
                    .certificate-container {
                        box-shadow: none;
                        page-break-inside: avoid;
                        width: 100vw;
                        height: 100vh;
                        max-width: 100%;
                        max-height: 100%;
                        padding: 45px 120px;
                    }
                }
            </style>
        </head>
        <body>
            <!-- Floating print action bar -->
            <div class="no-print" style="position: fixed; top: 0; left: 0; right: 0; height: 60px; background: #1F2937; display: flex; align-items: center; justify-content: space-between; padding: 0 30px; box-shadow: 0 4px 6px rgba(0,0,0,0.15); z-index: 9999; font-family: \'Montserrat\', sans-serif; box-sizing: border-box;">
                <div style="color: white; font-weight: 600; font-size: 15px; display: flex; align-items: center; gap: 8px;">
                    <svg width="20" height="20" fill="var(--cert-secondary)" viewBox="0 0 20 20" style="color: var(--cert-secondary);"><path d="M10.394 2.08a1 1 0 00-.788 0l-7 3a1 1 0 000 1.84L5.25 8.051a.999.999 0 01.356-.257l4-1.714a1 1 0 11.788 1.838L7.667 9.088l1.939.831a1 1 0 00.788 0l7-3a1 1 0 000-1.838l-7-3zM3.31 9.397L5 10.12v4.102a8.969 8.969 0 00-1.05-.174 1 1 0 01-.89-.89 11.115 11.115 0 01.25-3.762zM9.3 16.573A9.026 9.026 0 007 14.935v-3.957l1.818.78a3 3 0 002.364 0l5.508-2.361a11.026 11.026 0 01.25 3.762 1 1 0 01-.89.89 8.968 8.968 0 00-5.35 2.999 1 1 0 01-1.4 0z"/></svg>
                    <span>Official Certificate of ' . htmlspecialchars($school_name) . '</span>
                </div>
                <div style="display: flex; gap: 12px; align-items: center;">
                    <button onclick="window.print()" style="background: #3B82F6; color: white; border: none; padding: 8px 18px; border-radius: 6px; font-weight: 600; font-size: 13px; cursor: pointer; display: flex; align-items: center; gap: 6px; box-shadow: 0 2px 4px rgba(0,0,0,0.1); transition: background 0.15s ease;" onmouseover="this.style.background=\'#2563EB\'" onmouseout="this.style.background=\'#3B82F6\'">
                        <svg width="14" height="14" fill="currentColor" viewBox="0 0 24 24"><path d="M19 8H5c-1.66 0-3 1.34-3 3v6h4v4h12v-4h4v-6c0-1.66-1.34-3-3-3zm-3 11H8v-5h8v5zm3-7c-.55 0-1-.45-1-1s.45-1 1-1 1 .45 1 1-.45 1-1 1zm-1-9H6v4h12V3z"/></svg>
                        Print / Save as PDF
                    </button>
                    <button onclick="window.close()" style="background: #4B5563; color: white; border: none; padding: 8px 14px; border-radius: 6px; font-weight: 600; font-size: 13px; cursor: pointer; transition: background 0.15s ease;" onmouseover="this.style.background=\'#374151\'" onmouseout="this.style.background=\'#4B5563\'">
                        Close
                    </button>
                </div>
            </div>
            <div class="certificate-container">
                <!-- SVG Background Shapes & Lines -->
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 1000 700" preserveAspectRatio="none" style="position: absolute; top: 0; left: 0; width: 100%; height: 100%; z-index: 1; pointer-events: none;">
                    <defs>
                        <!-- Premium Foil Gold Gradient -->
                        <linearGradient id="goldGrad" x1="0%" y1="0%" x2="100%" y2="100%">
                            <stop offset="0%" stop-color="var(--cert-accent)" />
                            <stop offset="22%" stop-color="var(--cert-accent-light)" />
                            <stop offset="45%" stop-color="var(--cert-secondary)" />
                            <stop offset="65%" stop-color="var(--cert-accent-light)" />
                            <stop offset="88%" stop-color="var(--cert-secondary)" />
                            <stop offset="100%" stop-color="var(--cert-accent)" />
                        </linearGradient>
                        <!-- Dark Blue Gradient -->
                        <linearGradient id="blueGrad" x1="0%" y1="0%" x2="100%" y2="100%">
                            <stop offset="0%" stop-color="var(--cert-primary)" />
                            <stop offset="100%" stop-color="var(--cert-primary-dark)" />
                        </linearGradient>
                        <!-- Secondary Gold/Shadow Gradient -->
                        <linearGradient id="darkGoldGrad" x1="0%" y1="0%" x2="100%" y2="100%">
                            <stop offset="0%" stop-color="var(--cert-accent)" />
                            <stop offset="100%" stop-color="#5B4006" />
                        </linearGradient>
                        
                        <!-- Drop Shadows for depth -->
                        <filter id="shadowLeft" x="-10%" y="-10%" width="120%" height="120%">
                            <feDropShadow dx="-3" dy="2" stdDeviation="5" flood-color="#000000" flood-opacity="0.25" />
                        </filter>
                        <filter id="shadowRight" x="-10%" y="-10%" width="120%" height="120%">
                            <feDropShadow dx="3" dy="2" stdDeviation="5" flood-color="#000000" flood-opacity="0.25" />
                        </filter>
                        <filter id="badgeShadow" x="-20%" y="-20%" width="140%" height="140%">
                            <feDropShadow dx="0" dy="4" stdDeviation="4" flood-color="#000000" flood-opacity="0.3" />
                        </filter>
                    </defs>
                    
                    <!-- LEFT SIDE WAVES -->
                    <!-- Navy Blue Block -->
                    <path d="M 0,0 L 210,0 C 170,150 140,350 150,450 C 160,550 180,650 210,700 L 0,700 Z" fill="url(#blueGrad)" />
                    <!-- Gold Overlap Line -->
                    <path d="M 210,0 C 170,150 140,350 150,450 C 160,550 180,650 210,700" stroke="url(#goldGrad)" stroke-width="30" fill="none" filter="url(#shadowRight)" />

                    <!-- RIGHT SIDE WAVES -->
                    <!-- Navy Blue Block -->
                    <path d="M 1000,0 L 790,0 C 830,150 860,350 850,450 C 840,550 820,650 790,700 L 1000,700 Z" fill="url(#blueGrad)" />
                    <!-- Gold Overlap Line -->
                    <path d="M 790,0 C 830,150 860,350 850,450 C 840,550 820,650 790,700" stroke="url(#goldGrad)" stroke-width="30" fill="none" filter="url(#shadowLeft)" />

                    <!-- Gold Accent Horizontal Lines (Top and Bottom) -->
                    <!-- Top Line -->
                    <line x1="220" y1="130" x2="780" y2="130" stroke="url(#goldGrad)" stroke-width="2" />
                    <!-- Bottom Line -->
                    <line x1="220" y1="565" x2="780" y2="565" stroke="url(#goldGrad)" stroke-width="2" />
                </svg>

                <div class="certificate-content">
                    <div class="cert-header">
                        <div class="school-name">' . htmlspecialchars($school_name) . '</div>
                        <div class="school-motto">' . htmlspecialchars($school_motto) . '</div>
                        <h1 class="cert-main-title">CERTIFICATE</h1>
                        
                        <!-- 3D Ribbon Widget -->
                        <div style="margin: 12px auto 0 auto; text-align: center;">
                            <svg width="360" height="50" viewBox="0 0 360 50">
                                <!-- Left Ribbon Tail -->
                                <polygon points="35,10 10,10 22,23 10,36 35,36" fill="url(#goldGrad)" />
                                <polygon points="35,36 35,41 40,36" fill="url(#darkGoldGrad)" />
                                
                                <!-- Right Ribbon Tail -->
                                <polygon points="325,10 350,10 338,23 350,36 325,36" fill="url(#goldGrad)" />
                                <polygon points="325,36 325,41 320,36" fill="url(#darkGoldGrad)" />
                                
                                <!-- Center Ribbon Banner -->
                                <polygon points="40,10 320,10 320,36 40,36" fill="url(#goldGrad)" stroke="url(#goldGrad)" stroke-width="0.5" />
                                
                                <!-- Text inside Ribbon -->
                                <text x="50%" y="27" dominant-baseline="middle" text-anchor="middle" fill="white" font-family="\'Montserrat\', sans-serif" font-weight="bold" font-size="11" letter-spacing="3.5">' . htmlspecialchars($ribbon_text) . '</text>
                            </svg>
                        </div>
                    </div>
                    
                    <div class="cert-body">
                        <p class="cert-presentation">This certificate of ' . htmlspecialchars($type) . ' presented to :</p>
                        <div class="cert-student-name-container">
                            <h2 class="cert-student-name">' . htmlspecialchars($student['name']) . '</h2>
                            <div class="cert-name-underline"></div>
                        </div>
                        
                        <div class="cert-description">
                            ' . (!empty($description) ? nl2br(htmlspecialchars($description)) : '&nbsp;') . '
                        </div>
                    </div>
                    
                    <div class="cert-footer">
                        <div class="signature-block">
                            <div style="height:42px;display:flex;align-items:flex-end;justify-content:center;">' . $cert_head_img . '</div>
                            <div class="signature-line"></div>
                            <span class="signature-title">' . $cert_head_name . '</span>
                        </div>
                        
                        <!-- Center Gold Badge Seal -->
                        <div class="badge-block" filter="url(#badgeShadow)">
                            <svg width="120" height="135" viewBox="0 0 120 135" style="display: block;">
                                <!-- Ribbon Tails -->
                                <polygon points="45,60 30,125 45,115 60,125 52,60" fill="url(#goldGrad)" />
                                <polygon points="75,60 60,125 75,115 90,125 68,60" fill="url(#goldGrad)" />
                                
                                <g transform="translate(60, 60)">
                                    <!-- Starburst Outer Circle -->
                                    <path d="M 0,-48 L 5,-40 L 15,-45 L 18,-36 L 28,-38 L 29,-28 L 38,-28 L 36,-18 L 44,-16 L 40,-6 L 46,-3 L 40,7 L 44,11 L 37,18 L 40,24 L 31,29 L 32,36 L 23,38 L 21,46 L 12,45 L 8,51 L 0,48 L -8,51 L -12,45 L -23,46 L -21,38 L -32,36 L -31,29 L -40,24 L -37,18 L -44,11 L -40,7 L -46,-3 L -40,-6 L -44,-16 L -36,-18 L -38,-28 L -29,-28 L -28,-38 L -18,-36 L -15,-45 L -5,-40 Z" fill="url(#goldGrad)" />
                                    <!-- Inner Circle -->
                                    <circle r="38" fill="var(--cert-primary)" stroke="url(#goldGrad)" stroke-width="2.5" />
                                    
                                    <!-- Award Text & Stars -->
                                    <text x="0" y="-10" text-anchor="middle" fill="url(#goldGrad)" font-family="\'Montserrat\', sans-serif" font-weight="bold" font-size="8" letter-spacing="1.2">BEST</text>
                                    <text x="0" y="3" text-anchor="middle" fill="url(#goldGrad)" font-family="\'Montserrat\', sans-serif" font-weight="bold" font-size="8" letter-spacing="1.2">AWARD</text>
                                    <text x="0" y="18" text-anchor="middle" fill="url(#goldGrad)" font-size="11">★★★</text>
                                </g>
                            </svg>
                        </div>
                        
                        <div class="signature-block">
                            <div style="height:42px;display:flex;align-items:flex-end;justify-content:center;">' . $cert_reg_img . '</div>
                            <div class="signature-line"></div>
                            <span class="signature-title">' . $cert_reg_name . '</span>
                        </div>
                    </div>
                </div>
            </div>
        </body>
        </html>';
        
        return $html;
    } else {
        // Default Academic Certificate Template (Template 1)
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
                    padding: 100px 40px 40px 40px; /* Adjusted for top action bar */
                    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
                    min-height: calc(100vh - 140px);
                    display: flex;
                    align-items: center;
                    justify-content: center;
                    box-sizing: border-box;
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
                
                @page {
                    size: A4 landscape;
                    margin: 0;
                }

                @media print {
                    .no-print {
                        display: none !important;
                    }
                    body {
                        background: none;
                        padding: 0 !important;
                        margin: 0;
                    }
                    .certificate {
                        box-shadow: none;
                        border: none;
                        width: 100%;
                        height: 100vh;
                        box-sizing: border-box;
                    }
                }
            </style>
        </head>
        <body>
            <!-- Floating print action bar -->
            <div class="no-print" style="position: fixed; top: 0; left: 0; right: 0; height: 60px; background: #1F2937; display: flex; align-items: center; justify-content: space-between; padding: 0 30px; box-shadow: 0 4px 6px rgba(0,0,0,0.15); z-index: 9999; font-family: \'Arial\', sans-serif; box-sizing: border-box;">
                <div style="color: white; font-weight: bold; font-size: 15px; display: flex; align-items: center; gap: 8px;">
                    <span>Official Certificate of ' . htmlspecialchars($school_name) . '</span>
                </div>
                <div style="display: flex; gap: 12px; align-items: center;">
                    <button onclick="window.print()" style="background: #3B82F6; color: white; border: none; padding: 8px 18px; border-radius: 6px; font-weight: bold; font-size: 13px; cursor: pointer; display: flex; align-items: center; gap: 6px; box-shadow: 0 2px 4px rgba(0,0,0,0.1);" onmouseover="this.style.background=\'#2563EB\'" onmouseout="this.style.background=\'#3B82F6\'">
                        <svg width="14" height="14" fill="currentColor" viewBox="0 0 24 24"><path d="M19 8H5c-1.66 0-3 1.34-3 3v6h4v4h12v-4h4v-6c0-1.66-1.34-3-3-3zm-3 11H8v-5h8v5zm3-7c-.55 0-1-.45-1-1s.45-1 1-1 1 .45 1 1-.45 1-1 1zm-1-9H6v4h12V3z"/></svg>
                        Print / Save as PDF
                    </button>
                    <button onclick="window.close()" style="background: #4B5563; color: white; border: none; padding: 8px 14px; border-radius: 6px; font-weight: bold; font-size: 13px; cursor: pointer;" onmouseover="this.style.background=\'#374151\'" onmouseout="this.style.background=\'#4B5563\'">
                        Close
                    </button>
                </div>
            </div>
            <div class="certificate">
                <div class="seal">OFFICIAL<br>SEAL</div>
                
                <div class="header">
                    <div class="school-name">' . htmlspecialchars($school_name) . '</div>
                    <div style="font-size: 16px; color: #666; margin-bottom: 20px;">' . htmlspecialchars($school_motto) . '</div>
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
                        <div style="height:46px;display:flex;align-items:flex-end;justify-content:center;">' . $cert_head_img . '</div>
                        <div style="font-weight: bold; border-top:1px solid #333; padding-top:4px;">' . $cert_head_name . '</div>
                        <div style="font-size: 14px; color: #666;">' . htmlspecialchars($school_name) . '</div>
                    </div>
                    
                    <div class="date">
                        <strong>Date of Issue:</strong><br>
                        ' . date('F j, Y', strtotime($issue_date)) . '
                    </div>
                    
                    <div class="signature">
                        <div style="height:46px;display:flex;align-items:flex-end;justify-content:center;">' . $cert_reg_img . '</div>
                        <div style="font-weight: bold; border-top:1px solid #333; padding-top:4px;">' . $cert_reg_name . '</div>
                        <div style="font-size: 14px; color: #666;">Academic Office</div>
                    </div>
                </div>
            </div>
        </body>
        </html>';
        
        return $html;
    }
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
        <div class="max-w-6xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
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
                    <?php if (isset($generated_certificate_id)): ?>
                    <a href="download_certificate.php?id=<?php echo $generated_certificate_id; ?>&preview=1" target="_blank" class="bg-green-600 text-white px-3 py-1 rounded text-sm hover:bg-green-700">
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
                    <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
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
                                Certificate Template *
                            </label>
                            <select name="template_id" required class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg focus:ring-2 focus:ring-blue-500 dark:bg-gray-700 dark:text-white">
                                <option value="">Select template</option>
                                <?php foreach ($templates as $tmpl): ?>
                                <option value="<?php echo $tmpl['id']; ?>"><?php echo htmlspecialchars($tmpl['name']); ?></option>
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
