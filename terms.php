<?php
/**
 * Terms of Service — public legal page.
 */
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/includes/settings_helper.php';
$school_name = getSchoolSetting('school_name', 'School Management System');

$legal_title     = 'Terms of Service';
$legal_subtitle  = 'The rules and conditions for using the ' . $school_name . ' platform.';
$legal_icon      = 'fas fa-file-contract';
$legal_effective = date('F j, Y');
$legal_intro     = 'These Terms of Service ("Terms") govern your access to and use of the ' . htmlspecialchars($school_name)
    . ' management system. By logging in or using the system, you agree to be bound by these Terms. If you do not agree, please do not use the system.';

$legal_sections = [
    [
        'id' => 'acceptance',
        'title' => 'Acceptance of Terms',
        'icon' => 'fas fa-handshake',
        'body' => '
            <p>By accessing the system, you confirm that you are authorised to use it and that you accept these Terms in full. Access is granted to registered users only — students, parents/guardians, teachers, staff and administrators — and is subject to the role assigned to your account.</p>',
    ],
    [
        'id' => 'accounts',
        'title' => 'User Accounts &amp; Security',
        'icon' => 'fas fa-user-lock',
        'body' => '
            <p>You are responsible for maintaining the confidentiality of your login credentials and for all activity that occurs under your account. You agree to:</p>
            <ul>
                <li>Provide accurate and up-to-date information.</li>
                <li>Keep your password secure and not share it with others.</li>
                <li>Notify the administration immediately of any unauthorised use of your account.</li>
            </ul>
            <p>The school may suspend or terminate accounts that violate these Terms or pose a security risk.</p>',
    ],
    [
        'id' => 'acceptable-use',
        'title' => 'Acceptable Use',
        'icon' => 'fas fa-circle-check',
        'body' => '
            <p>When using the system, you agree <strong>not</strong> to:</p>
            <ul>
                <li>Access data or features you are not authorised to use.</li>
                <li>Attempt to disrupt, damage or gain unauthorised access to the system.</li>
                <li>Upload malicious code, spam, or unlawful, harmful or offensive content.</li>
                <li>Use the system to harass, intimidate or violate the rights of others.</li>
                <li>Copy, distribute or misuse data accessed through the system.</li>
            </ul>',
    ],
    [
        'id' => 'content',
        'title' => 'User Content',
        'icon' => 'fas fa-pen-to-square',
        'body' => '
            <p>You retain responsibility for any content you submit, such as messages, assignments, comments or uploaded files. You must ensure that your content is accurate, lawful and respectful. The school reserves the right to review and remove content that breaches these Terms or applicable policies.</p>',
    ],
    [
        'id' => 'intellectual-property',
        'title' => 'Intellectual Property',
        'icon' => 'fas fa-copyright',
        'body' => '
            <p>The system, including its software, design, logos and content provided by the school, is protected by intellectual-property rights. You may use it only for its intended educational and administrative purposes. You may not reproduce, modify or redistribute any part of the system without prior written permission.</p>',
    ],
    [
        'id' => 'availability',
        'title' => 'Service Availability',
        'icon' => 'fas fa-server',
        'body' => '
            <p>We aim to keep the system available and reliable, but we do not guarantee uninterrupted access. The system may be temporarily unavailable for maintenance, upgrades or reasons beyond our control. We are not liable for losses arising from any downtime or service interruption.</p>',
    ],
    [
        'id' => 'liability',
        'title' => 'Limitation of Liability',
        'icon' => 'fas fa-scale-balanced',
        'body' => '
            <p>To the maximum extent permitted by law, the school and its providers shall not be liable for any indirect, incidental or consequential damages arising from your use of, or inability to use, the system. The system is provided on an "as is" and "as available" basis without warranties of any kind.</p>',
    ],
    [
        'id' => 'termination',
        'title' => 'Termination',
        'icon' => 'fas fa-ban',
        'body' => '
            <p>We may suspend or terminate your access to the system at any time if you breach these Terms, if your association with the school ends, or where necessary to protect the system and its users. Upon termination, your right to use the system ceases immediately.</p>',
    ],
    [
        'id' => 'changes',
        'title' => 'Changes to These Terms',
        'icon' => 'fas fa-rotate',
        'body' => '
            <p>We may revise these Terms from time to time. The "Last updated" date at the top of this page reflects the latest version. Your continued use of the system after changes are posted constitutes acceptance of the revised Terms.</p>',
    ],
];

require __DIR__ . '/includes/legal_template.php';
