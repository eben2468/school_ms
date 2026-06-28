<?php
/**
 * Privacy Policy — public legal page.
 */
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/includes/settings_helper.php';
$school_name  = getSchoolSetting('school_name', 'School Management System');
$school_email = getSchoolSetting('school_email', 'info@school.edu');

$legal_title     = 'Privacy Policy';
$legal_subtitle  = 'How ' . $school_name . ' collects, uses, and protects your personal information.';
$legal_icon      = 'fas fa-shield-alt';
$legal_effective = date('F j, Y');
$legal_intro     = 'Your privacy matters to us. This Privacy Policy explains what information we collect through the '
    . htmlspecialchars($school_name) . ' management system, how we use and safeguard it, and the rights you have over your data. '
    . 'By accessing or using the system, you agree to the practices described below.';

$legal_sections = [
    [
        'id' => 'information-we-collect',
        'title' => 'Information We Collect',
        'icon' => 'fas fa-database',
        'body' => '
            <p>We collect information that is necessary to operate the school management system and provide educational services. This includes:</p>
            <ul>
                <li><strong>Account &amp; identity data:</strong> names, email addresses, phone numbers, roles (student, parent, teacher, staff, administrator) and login credentials.</li>
                <li><strong>Academic records:</strong> enrolment details, class assignments, grades, examination results, attendance and assignment submissions.</li>
                <li><strong>Family &amp; relationship data:</strong> parent/guardian links to students and emergency contact information.</li>
                <li><strong>Operational data:</strong> fee and payment records, library activity, hostel, transport, canteen and health records where applicable.</li>
                <li><strong>Technical data:</strong> IP address, browser type, device information and activity logs used to keep the system secure.</li>
            </ul>',
    ],
    [
        'id' => 'how-we-use',
        'title' => 'How We Use Your Information',
        'icon' => 'fas fa-cogs',
        'body' => '
            <p>Information is used strictly for legitimate educational and administrative purposes, including:</p>
            <ul>
                <li>Managing enrolment, academic progress, attendance and reporting.</li>
                <li>Facilitating communication between staff, students and parents/guardians.</li>
                <li>Processing fees, payments and other school services.</li>
                <li>Maintaining the security, integrity and reliability of the system.</li>
                <li>Complying with legal, regulatory and safeguarding obligations.</li>
            </ul>
            <p>We do <strong>not</strong> sell your personal information or use it for third-party advertising.</p>',
    ],
    [
        'id' => 'data-sharing',
        'title' => 'How We Share Information',
        'icon' => 'fas fa-share-nodes',
        'body' => '
            <p>Access to data is controlled by role-based permissions, so users only see information relevant to their responsibilities. We may share information with:</p>
            <ul>
                <li>Authorised staff and administrators of the school.</li>
                <li>Parents or guardians, limited to their own children\'s records.</li>
                <li>Service providers who help operate the system under confidentiality obligations.</li>
                <li>Government or regulatory bodies where required by law.</li>
            </ul>',
    ],
    [
        'id' => 'data-security',
        'title' => 'Data Security',
        'icon' => 'fas fa-lock',
        'body' => '
            <p>We apply technical and organisational safeguards to protect your information, including encrypted passwords, role-based access controls, secure connections and regular backups. While we work hard to protect your data, no method of transmission or storage is completely secure, and we cannot guarantee absolute security.</p>',
    ],
    [
        'id' => 'data-retention',
        'title' => 'Data Retention',
        'icon' => 'fas fa-clock-rotate-left',
        'body' => '
            <p>We retain personal information for as long as it is needed to provide our services and to meet legal, accounting or reporting requirements. Academic records may be retained for extended periods in line with educational regulations. When data is no longer required, it is securely deleted or anonymised.</p>',
    ],
    [
        'id' => 'your-rights',
        'title' => 'Your Rights',
        'icon' => 'fas fa-user-shield',
        'body' => '
            <p>Subject to applicable law, you may have the right to:</p>
            <ul>
                <li>Access the personal information we hold about you.</li>
                <li>Request correction of inaccurate or incomplete data.</li>
                <li>Request deletion of your data where there is no legal reason to retain it.</li>
                <li>Object to or restrict certain processing of your data.</li>
            </ul>
            <p>To exercise any of these rights, please contact the school administration using the details below.</p>',
    ],
    [
        'id' => 'children-privacy',
        'title' => 'Children\'s Privacy',
        'icon' => 'fas fa-child',
        'body' => '
            <p>The system stores records relating to students who may be minors. Such data is entered and managed by authorised school staff and accessed by parents/guardians on behalf of their children. We handle this information with particular care and in accordance with applicable child-protection and data-protection laws.</p>',
    ],
    [
        'id' => 'changes',
        'title' => 'Changes to This Policy',
        'icon' => 'fas fa-rotate',
        'body' => '
            <p>We may update this Privacy Policy from time to time to reflect changes in our practices or legal requirements. The "Last updated" date at the top of this page indicates when the policy was last revised. Continued use of the system after changes are posted constitutes acceptance of the updated policy.</p>',
    ],
];

require __DIR__ . '/includes/legal_template.php';
