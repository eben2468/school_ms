<?php
/**
 * _print_timetable.php
 * Shared printable timetable used by the read-only teacher/student views.
 * Expects the following variables to be set in the including scope:
 *   $print_schedules    array  - rows from class_schedule (with subject_name, teacher_name, is_break, break_name)
 *   $print_time_slots   array  - ordered list of distinct time_slot strings
 *   $print_class_label  string - e.g. "Grade 4 - Basic 4"
 *
 * Outputs a <script> defining printTimetable(), which opens a styled print window.
 */
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/settings_helper.php';

$pt_settings    = function_exists('getSchoolSettings') ? getSchoolSettings() : [];
$pt_school_name = $pt_settings['school_name'] ?? 'School';
$pt_logo        = function_exists('getSchoolLogo') ? getSchoolLogo() : '';
$pt_year        = function_exists('getCurrentAcademicYear') ? getCurrentAcademicYear() : '';
$pt_address     = $pt_settings['school_address'] ?? '';
$pt_phone       = $pt_settings['school_phone'] ?? '';
$pt_email       = $pt_settings['school_email'] ?? '';
$pt_website     = $pt_settings['school_website'] ?? '';

$pt_days = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday'];

// Build the table body server-side from the data the page already has.
$pt_rows = '';
foreach (($print_time_slots ?? []) as $ts) {
    $is_break = false;
    $break_name = 'Break';
    foreach (($print_schedules ?? []) as $s) {
        if ($s['time_slot'] === $ts && !empty($s['is_break'])) {
            $is_break = true;
            $break_name = $s['break_name'] ?: 'Break';
            break;
        }
    }

    $pt_rows .= '<tr>';
    $pt_rows .= '<td class="time-cell">' . htmlspecialchars($ts) . '</td>';

    if ($is_break) {
        $pt_rows .= '<td colspan="5" class="break-cell"><span class="break-icon">&#9749;</span> ' . htmlspecialchars(strtoupper($break_name)) . '</td>';
    } else {
        foreach ($pt_days as $day) {
            $slot = null;
            foreach (($print_schedules ?? []) as $s) {
                if ($s['day'] === $day && $s['time_slot'] === $ts) { $slot = $s; break; }
            }
            if ($slot && !empty($slot['subject_name'])) {
                $pt_rows .= '<td class="class-cell"><div class="subject">' . htmlspecialchars($slot['subject_name']) . '</div>';
                if (!empty($slot['teacher_name'])) {
                    $pt_rows .= '<div class="teacher">' . htmlspecialchars($slot['teacher_name']) . '</div>';
                }
                $pt_rows .= '</td>';
            } else {
                $pt_rows .= '<td class="class-cell empty-cell"><span class="no-class">-</span></td>';
            }
        }
    }
    $pt_rows .= '</tr>';
}
?>
<script>
function printTimetable() {
    const schoolName    = <?php echo json_encode($pt_school_name); ?>;
    const schoolLogo    = <?php echo json_encode($pt_logo); ?>;
    const academicYear  = <?php echo json_encode($pt_year); ?>;
    const schoolAddress = <?php echo json_encode($pt_address); ?>;
    const schoolPhone   = <?php echo json_encode($pt_phone); ?>;
    const schoolEmail   = <?php echo json_encode($pt_email); ?>;
    const schoolWebsite = <?php echo json_encode($pt_website); ?>;
    const className     = <?php echo json_encode($print_class_label ?? ''); ?>;
    const tableRows     = <?php echo json_encode($pt_rows); ?>;

    const currentDate = new Date().toLocaleDateString('en-US', {
        weekday: 'long', year: 'numeric', month: 'long', day: 'numeric'
    });
    const logoImgHtml = schoolLogo ? `<img src="${schoolLogo}" alt="School Logo" class="school-logo">` : '';

    const printWindow = window.open('', '_blank');
    printWindow.document.write(`
        <!DOCTYPE html>
        <html>
        <head>
            <title>Class Timetable - ${className}</title>
            <style>
                @import url('https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap');
                * { box-sizing: border-box; margin: 0; padding: 0; }
                body { font-family: 'Inter', -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; color: #1f2937; line-height: 1.5; padding: 40px; background:#fff; }
                .print-header { display:flex; align-items:center; border-bottom:3px double #e5e7eb; padding-bottom:20px; margin-bottom:30px; }
                .school-logo { max-height:90px; width:auto; object-fit:contain; margin-right:25px; }
                .header-info { flex-grow:1; }
                .school-name { font-size:26px; font-weight:700; color:#1e3a8a; letter-spacing:-0.025em; margin-bottom:4px; text-transform:uppercase; }
                .school-details { font-size:13px; color:#4b5563; }
                .school-details span { margin-right:15px; display:inline-block; }
                .school-title-container { display:flex; justify-content:space-between; align-items:flex-end; margin-bottom:20px; }
                .timetable-title { font-size:20px; font-weight:700; color:#111827; letter-spacing:-0.02em; }
                .academic-meta { font-size:13px; color:#4b5563; font-weight:500; background:#f3f4f6; padding:4px 12px; border-radius:9999px; border:1px solid #e5e7eb; }
                .print-table { width:100%; border-collapse:collapse; margin-bottom:30px; }
                .print-table th, .print-table td { border:1px solid #d1d5db; padding:12px 10px; text-align:center; vertical-align:middle; }
                .print-table th { background:#1e3a8a; color:#fff; font-weight:600; font-size:13px; text-transform:uppercase; letter-spacing:0.05em; }
                .time-cell { font-weight:600; color:#374151; font-size:12px; background:#f9fafb; }
                .break-cell { background:#f3e8ff; color:#6b21a8; font-weight:700; font-size:14px; letter-spacing:0.1em; border:1px solid #d8b4fe; }
                .break-icon { margin-right:6px; }
                .class-cell { font-size:12px; }
                .subject { font-weight:600; color:#111827; margin-bottom:2px; }
                .teacher { color:#6b7280; font-size:11px; font-style:italic; }
                .empty-cell { background:#fafafa; }
                .no-class { color:#d1d5db; font-size:16px; }
                .print-footer { display:flex; justify-content:space-between; align-items:center; font-size:11px; color:#9ca3af; margin-top:50px; border-top:1px solid #e5e7eb; padding-top:15px; }
                .signature-block { text-align:right; font-size:12px; color:#4b5563; }
                .sig-line { width:200px; border-bottom:1px solid #9ca3af; margin-bottom:6px; height:40px; display:inline-block; }
                @media print {
                    body { padding:0; }
                    .print-table th { background:#1e3a8a !important; color:#fff !important; -webkit-print-color-adjust:exact; print-color-adjust:exact; }
                    .break-cell { background:#f3e8ff !important; color:#6b21a8 !important; -webkit-print-color-adjust:exact; print-color-adjust:exact; }
                    .time-cell { background:#f9fafb !important; -webkit-print-color-adjust:exact; print-color-adjust:exact; }
                }
            </style>
        </head>
        <body>
            <div class="print-header">
                ${logoImgHtml}
                <div class="header-info">
                    <h1 class="school-name">${schoolName}</h1>
                    <div class="school-details">
                        ${schoolAddress ? `<span><strong>Add:</strong> ${schoolAddress}</span>` : ''}
                        ${schoolPhone ? `<span><strong>Tel:</strong> ${schoolPhone}</span>` : ''}
                        ${schoolEmail ? `<span><strong>Email:</strong> ${schoolEmail}</span>` : ''}
                        ${schoolWebsite ? `<span><strong>Web:</strong> ${schoolWebsite}</span>` : ''}
                    </div>
                </div>
            </div>
            <div class="school-title-container">
                <div class="timetable-title">Class Timetable: ${className}</div>
                <div class="academic-meta">Academic Year: ${academicYear}</div>
            </div>
            <table class="print-table">
                <thead>
                    <tr>
                        <th style="width:15%;">Time</th>
                        <th style="width:17%;">Monday</th>
                        <th style="width:17%;">Tuesday</th>
                        <th style="width:17%;">Wednesday</th>
                        <th style="width:17%;">Thursday</th>
                        <th style="width:17%;">Friday</th>
                    </tr>
                </thead>
                <tbody>${tableRows}</tbody>
            </table>
            <div class="print-footer">
                <div>Generated on: ${currentDate} | School Management System</div>
                <div class="signature-block">
                    <div class="sig-line"></div><br>
                    <div>Class Teacher / Administrator Signature</div>
                </div>
            </div>
        </body>
        </html>
    `);
    printWindow.document.close();
    printWindow.onload = function() { printWindow.focus(); printWindow.print(); };
}

// Ctrl/Cmd+P prints the formatted timetable instead of the whole page
document.addEventListener('keydown', function(e) {
    if ((e.ctrlKey || e.metaKey) && e.key === 'p') {
        e.preventDefault();
        printTimetable();
    }
});
</script>
