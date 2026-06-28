<?php
$db = new PDO('mysql:host=localhost;dbname=school_ms', 'root', '');

echo "=== term_reports schema ===\n";
try {
    $stmt = $db->query("DESCRIBE term_reports");
    $cols = $stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach($cols as $c) echo "  {$c['Field']} ({$c['Type']})\n";
} catch(Exception $e) { echo "NOT FOUND: " . $e->getMessage() . "\n"; }

echo "\n=== Verify: students with records for year 1, term 3, class 1 (Basic 1) ===\n";
$stmt = $db->query("
    SELECT u.id, u.name, COUNT(sar.id) as subjects, ROUND(AVG(sar.total_score),1) as avg_score
    FROM users u
    JOIN student_profiles sp ON u.id = sp.user_id
    JOIN student_classes sc ON u.id = sc.student_id AND sc.status = 'active'
    LEFT JOIN student_academic_records sar ON u.id = sar.student_id 
        AND sar.academic_year_id = 1 AND sar.academic_term_id = 3
    WHERE u.role = 'student' AND u.status = 'active' AND sc.class_id = 1
    GROUP BY u.id, u.name
    ORDER BY u.name LIMIT 15
");
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
foreach($rows as $r) echo "  {$r['name']} | subjects:{$r['subjects']} | avg:{$r['avg_score']}\n";

echo "\n=== attendance table check ===\n";
try {
    $stmt = $db->query("DESCRIBE attendance");
    $cols = $stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach($cols as $c) echo "  {$c['Field']} ({$c['Type']})\n";
} catch(Exception $e) { echo "NOT FOUND: " . $e->getMessage() . "\n"; }
