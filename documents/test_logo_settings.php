<?php
session_start();
$_SESSION['user_id'] = 1;
$_SESSION['role'] = 'super_admin';

require_once '../config/database.php';
require_once '../includes/settings_helper.php';

echo "school_logo setting: " . getSchoolSetting('school_logo') . "\n";
echo "getSchoolLogo(): " . getSchoolLogo() . "\n";
$logo_name = getSchoolSetting('school_logo', '');
$logo_file_path = __DIR__ . '/../uploads/logos/' . $logo_name;
echo "logo_file_path: " . $logo_file_path . "\n";
echo "file_exists: " . (file_exists($logo_file_path) ? 'YES' : 'NO') . "\n";
if (file_exists($logo_file_path)) {
    echo "file size: " . filesize($logo_file_path) . "\n";
}
?>
