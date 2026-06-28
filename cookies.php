<?php
/**
 * Cookie Policy — public legal page.
 */
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/includes/settings_helper.php';
$school_name = getSchoolSetting('school_name', 'School Management System');

$legal_title     = 'Cookie Policy';
$legal_subtitle  = 'How and why ' . $school_name . ' uses cookies and similar technologies.';
$legal_icon      = 'fas fa-cookie-bite';
$legal_effective = date('F j, Y');
$legal_intro     = 'This Cookie Policy explains what cookies are, which cookies the ' . htmlspecialchars($school_name)
    . ' system uses, and how you can manage them. We use cookies only where necessary to make the system work and to improve your experience.';

$cookie_table = '
    <div class="overflow-x-auto rounded-xl border border-gray-200 my-4">
        <table class="w-full text-sm text-left">
            <thead class="bg-gray-50 text-gray-600 uppercase text-xs">
                <tr>
                    <th class="px-4 py-3 font-semibold">Cookie</th>
                    <th class="px-4 py-3 font-semibold">Type</th>
                    <th class="px-4 py-3 font-semibold">Purpose</th>
                    <th class="px-4 py-3 font-semibold">Duration</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
                <tr>
                    <td class="px-4 py-3 font-medium text-gray-900">PHPSESSID</td>
                    <td class="px-4 py-3">Essential</td>
                    <td class="px-4 py-3">Maintains your login session so you stay signed in as you navigate.</td>
                    <td class="px-4 py-3">Session</td>
                </tr>
                <tr>
                    <td class="px-4 py-3 font-medium text-gray-900">remember_token</td>
                    <td class="px-4 py-3">Functional</td>
                    <td class="px-4 py-3">Keeps you signed in when you choose "Remember me" at login.</td>
                    <td class="px-4 py-3">Up to 30 days</td>
                </tr>
                <tr>
                    <td class="px-4 py-3 font-medium text-gray-900">Preferences</td>
                    <td class="px-4 py-3">Functional</td>
                    <td class="px-4 py-3">Stores interface choices such as theme, sidebar state and dismissed notices.</td>
                    <td class="px-4 py-3">Persistent</td>
                </tr>
            </tbody>
        </table>
    </div>';

$legal_sections = [
    [
        'id' => 'what-are-cookies',
        'title' => 'What Are Cookies?',
        'icon' => 'fas fa-circle-question',
        'body' => '
            <p>Cookies are small text files placed on your device when you visit a website or web application. They allow the system to recognise your device, keep you signed in, and remember your preferences. Similar technologies such as local storage may also be used for the same purposes.</p>',
    ],
    [
        'id' => 'how-we-use-cookies',
        'title' => 'How We Use Cookies',
        'icon' => 'fas fa-cookie',
        'body' => '
            <p>We use cookies to keep the system secure and functional. Specifically, cookies help us to:</p>
            <ul>
                <li>Authenticate users and maintain secure login sessions.</li>
                <li>Remember your login when you select "Remember me".</li>
                <li>Store interface preferences such as theme and layout.</li>
                <li>Keep the system stable, secure and performant.</li>
            </ul>',
    ],
    [
        'id' => 'types-of-cookies',
        'title' => 'Cookies We Use',
        'icon' => 'fas fa-list',
        'body' => '
            <p>The system primarily relies on <strong>essential</strong> and <strong>functional</strong> cookies. We do not use cookies for third-party advertising. The table below summarises the main cookies in use:</p>'
            . $cookie_table,
    ],
    [
        'id' => 'managing-cookies',
        'title' => 'Managing Cookies',
        'icon' => 'fas fa-sliders',
        'body' => '
            <p>Most web browsers let you view, delete and block cookies through their settings. Helpful links include:</p>
            <ul>
                <li><a href="https://support.google.com/chrome/answer/95647" target="_blank" rel="noopener">Google Chrome</a></li>
                <li><a href="https://support.mozilla.org/en-US/kb/cookies-information-websites-store-on-your-computer" target="_blank" rel="noopener">Mozilla Firefox</a></li>
                <li><a href="https://support.microsoft.com/en-us/microsoft-edge/delete-cookies-in-microsoft-edge-63947406-40ac-c3b8-57b9-2a946a29ae09" target="_blank" rel="noopener">Microsoft Edge</a></li>
                <li><a href="https://support.apple.com/en-us/HT201265" target="_blank" rel="noopener">Apple Safari</a></li>
            </ul>
            <p><strong>Please note:</strong> blocking essential cookies will prevent you from logging in and using core features of the system.</p>',
    ],
    [
        'id' => 'third-party',
        'title' => 'Third-Party Resources',
        'icon' => 'fas fa-globe',
        'body' => '
            <p>The system loads some resources, such as fonts and icon libraries, from trusted content-delivery networks. These providers may set their own cookies governed by their respective privacy policies. We use these services only to deliver the interface and not for tracking.</p>',
    ],
    [
        'id' => 'changes',
        'title' => 'Changes to This Policy',
        'icon' => 'fas fa-rotate',
        'body' => '
            <p>We may update this Cookie Policy to reflect changes in the technologies we use or for legal reasons. The "Last updated" date at the top of this page shows when it was last revised. We encourage you to review this page periodically.</p>',
    ],
];

require __DIR__ . '/includes/legal_template.php';
