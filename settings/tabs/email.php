<!-- Email Integration Tab -->
<div class="space-y-6">
    <div class="flex items-center justify-between mb-6">
        <div>
            <h2 class="text-2xl font-semibold text-gray-900 dark:text-white">Email Integration</h2>
            <p class="text-gray-600 dark:text-gray-400 mt-1">Configure SMTP settings and email notification preferences</p>
        </div>
    </div>

    <form method="POST" class="space-y-8">
        <input type="hidden" name="action" value="update_email">

        <!-- Email Notification Status -->
        <div>
            <h3 class="text-lg font-medium text-gray-900 dark:text-white mb-4">Email Notification Settings</h3>
            <div>
                <label for="email_notifications" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                    Email Notifications
                </label>
                <select id="email_notifications" name="email_notifications"
                    class="w-full px-4 py-3 border border-gray-300 dark:border-gray-600 rounded-lg focus:outline-none focus:ring-2 focus:ring-indigo-500 dark:bg-gray-700 dark:text-white">
                    <option value="enabled" <?php echo $settings['email_notifications'] === 'enabled' ? 'selected' : ''; ?>>Enabled for All Users</option>
                    <option value="disabled" <?php echo $settings['email_notifications'] === 'disabled' ? 'selected' : ''; ?>>Disabled</option>
                    <option value="admin_only" <?php echo $settings['email_notifications'] === 'admin_only' ? 'selected' : ''; ?>>Admin Only</option>
                </select>
            </div>
        </div>

        <!-- SMTP Configuration -->
        <div>
            <h3 class="text-lg font-medium text-gray-900 dark:text-white mb-4">SMTP Server Configuration</h3>
            <div class="space-y-6">
                <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                    <!-- SMTP Host -->
                    <div>
                        <label for="smtp_host" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                            SMTP Host
                        </label>
                        <input type="text" id="smtp_host" name="smtp_host"
                            value="<?php echo htmlspecialchars($settings['smtp_host'] ?? ''); ?>"
                            placeholder="smtp.gmail.com"
                            class="w-full px-4 py-3 border border-gray-300 dark:border-gray-600 rounded-lg focus:outline-none focus:ring-2 focus:ring-indigo-500 dark:bg-gray-700 dark:text-white">
                    </div>

                    <!-- SMTP Port -->
                    <div>
                        <label for="smtp_port" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                            SMTP Port
                        </label>
                        <input type="number" id="smtp_port" name="smtp_port"
                            value="<?php echo htmlspecialchars($settings['smtp_port'] ?? '587'); ?>"
                            placeholder="587"
                            class="w-full px-4 py-3 border border-gray-300 dark:border-gray-600 rounded-lg focus:outline-none focus:ring-2 focus:ring-indigo-500 dark:bg-gray-700 dark:text-white">
                    </div>
                </div>

                <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                    <!-- SMTP Username -->
                    <div>
                        <label for="smtp_username" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                            SMTP Username / Email
                        </label>
                        <input type="text" id="smtp_username" name="smtp_username"
                            value="<?php echo htmlspecialchars($settings['smtp_username'] ?? ''); ?>"
                            placeholder="your-email@gmail.com"
                            class="w-full px-4 py-3 border border-gray-300 dark:border-gray-600 rounded-lg focus:outline-none focus:ring-2 focus:ring-indigo-500 dark:bg-gray-700 dark:text-white">
                    </div>

                    <!-- SMTP Password -->
                    <div>
                        <label for="smtp_password" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                            SMTP Password
                        </label>
                        <input type="password" id="smtp_password" name="smtp_password"
                            value="<?php echo htmlspecialchars($settings['smtp_password'] ?? ''); ?>"
                            placeholder="Enter SMTP password"
                            class="w-full px-4 py-3 border border-gray-300 dark:border-gray-600 rounded-lg focus:outline-none focus:ring-2 focus:ring-indigo-500 dark:bg-gray-700 dark:text-white">
                    </div>
                </div>

                <!-- SMTP Encryption -->
                <div>
                    <label for="smtp_encryption" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                        Encryption Method
                    </label>
                    <select id="smtp_encryption" name="smtp_encryption"
                        class="w-full px-4 py-3 border border-gray-300 dark:border-gray-600 rounded-lg focus:outline-none focus:ring-2 focus:ring-indigo-500 dark:bg-gray-700 dark:text-white">
                        <option value="tls" <?php echo ($settings['smtp_encryption'] ?? 'tls') === 'tls' ? 'selected' : ''; ?>>TLS (Recommended)</option>
                        <option value="ssl" <?php echo ($settings['smtp_encryption'] ?? 'tls') === 'ssl' ? 'selected' : ''; ?>>SSL</option>
                        <option value="none" <?php echo ($settings['smtp_encryption'] ?? 'tls') === 'none' ? 'selected' : ''; ?>>None</option>
                    </select>
                </div>
            </div>
        </div>

        <!-- SMTP Setup Guide -->
        <div class="bg-blue-50 dark:bg-blue-900/20 rounded-lg p-6 border border-blue-200 dark:border-blue-800">
            <h3 class="text-lg font-medium text-gray-900 dark:text-white mb-4 flex items-center">
                <i class="fas fa-info-circle text-blue-600 dark:text-blue-400 mr-2"></i>
                SMTP Configuration Guide
            </h3>
            <div class="space-y-3 text-sm text-gray-700 dark:text-gray-300">
                <div class="flex items-start">
                    <i class="fas fa-check-circle text-green-600 dark:text-green-400 mr-2 mt-1"></i>
                    <p><strong>Gmail:</strong> smtp.gmail.com, Port 587 (TLS) or 465 (SSL). Use App Password if 2FA is enabled</p>
                </div>
                <div class="flex items-start">
                    <i class="fas fa-check-circle text-green-600 dark:text-green-400 mr-2 mt-1"></i>
                    <p><strong>Outlook/Office 365:</strong> smtp.office365.com, Port 587 (TLS)</p>
                </div>
                <div class="flex items-start">
                    <i class="fas fa-check-circle text-green-600 dark:text-green-400 mr-2 mt-1"></i>
                    <p><strong>SendGrid:</strong> smtp.sendgrid.net, Port 587 (TLS), Username: apikey</p>
                </div>
            </div>
        </div>

        <!-- Email Notification Triggers -->
        <div>
            <h3 class="text-lg font-medium text-gray-900 dark:text-white mb-4">Email Notification Triggers</h3>
            <div class="space-y-3">
                <div class="flex items-center justify-between p-4 bg-gray-50 dark:bg-gray-900 rounded-lg">
                    <div>
                        <p class="font-medium text-gray-900 dark:text-white">Welcome Emails</p>
                        <p class="text-sm text-gray-600 dark:text-gray-400">Send welcome email to new users</p>
                    </div>
                    <label class="relative inline-flex items-center cursor-pointer">
                        <input type="checkbox" class="sr-only peer" checked>
                        <div class="w-11 h-6 bg-gray-200 peer-focus:outline-none peer-focus:ring-4 peer-focus:ring-blue-300 dark:peer-focus:ring-blue-800 rounded-full peer dark:bg-gray-700 peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-gray-300 after:border after:rounded-full after:h-5 after:w-5 after:transition-all dark:border-gray-600 peer-checked:bg-blue-600"></div>
                    </label>
                </div>

                <div class="flex items-center justify-between p-4 bg-gray-50 dark:bg-gray-900 rounded-lg">
                    <div>
                        <p class="font-medium text-gray-900 dark:text-white">Password Reset</p>
                        <p class="text-sm text-gray-600 dark:text-gray-400">Send password reset links via email</p>
                    </div>
                    <label class="relative inline-flex items-center cursor-pointer">
                        <input type="checkbox" class="sr-only peer" checked>
                        <div class="w-11 h-6 bg-gray-200 peer-focus:outline-none peer-focus:ring-4 peer-focus:ring-blue-300 dark:peer-focus:ring-blue-800 rounded-full peer dark:bg-gray-700 peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-gray-300 after:border after:rounded-full after:h-5 after:w-5 after:transition-all dark:border-gray-600 peer-checked:bg-blue-600"></div>
                    </label>
                </div>

                <div class="flex items-center justify-between p-4 bg-gray-50 dark:bg-gray-900 rounded-lg">
                    <div>
                        <p class="font-medium text-gray-900 dark:text-white">Grade Reports</p>
                        <p class="text-sm text-gray-600 dark:text-gray-400">Email report cards to parents</p>
                    </div>
                    <label class="relative inline-flex items-center cursor-pointer">
                        <input type="checkbox" class="sr-only peer" checked>
                        <div class="w-11 h-6 bg-gray-200 peer-focus:outline-none peer-focus:ring-4 peer-focus:ring-blue-300 dark:peer-focus:ring-blue-800 rounded-full peer dark:bg-gray-700 peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-gray-300 after:border after:rounded-full after:h-5 after:w-5 after:transition-all dark:border-gray-600 peer-checked:bg-blue-600"></div>
                    </label>
                </div>

                <div class="flex items-center justify-between p-4 bg-gray-50 dark:bg-gray-900 rounded-lg">
                    <div>
                        <p class="font-medium text-gray-900 dark:text-white">Assignment Notifications</p>
                        <p class="text-sm text-gray-600 dark:text-gray-400">Notify students of new assignments</p>
                    </div>
                    <label class="relative inline-flex items-center cursor-pointer">
                        <input type="checkbox" class="sr-only peer">
                        <div class="w-11 h-6 bg-gray-200 peer-focus:outline-none peer-focus:ring-4 peer-focus:ring-blue-300 dark:peer-focus:ring-blue-800 rounded-full peer dark:bg-gray-700 peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-gray-300 after:border after:rounded-full after:h-5 after:w-5 after:transition-all dark:border-gray-600 peer-checked:bg-blue-600"></div>
                    </label>
                </div>

                <div class="flex items-center justify-between p-4 bg-gray-50 dark:bg-gray-900 rounded-lg">
                    <div>
                        <p class="font-medium text-gray-900 dark:text-white">Event Reminders</p>
                        <p class="text-sm text-gray-600 dark:text-gray-400">Send reminders for upcoming events</p>
                    </div>
                    <label class="relative inline-flex items-center cursor-pointer">
                        <input type="checkbox" class="sr-only peer" checked>
                        <div class="w-11 h-6 bg-gray-200 peer-focus:outline-none peer-focus:ring-4 peer-focus:ring-blue-300 dark:peer-focus:ring-blue-800 rounded-full peer dark:bg-gray-700 peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-gray-300 after:border after:rounded-full after:h-5 after:w-5 after:transition-all dark:border-gray-600 peer-checked:bg-blue-600"></div>
                    </label>
                </div>
            </div>
        </div>

        <!-- Test Email -->
        <div class="bg-yellow-50 dark:bg-yellow-900/20 rounded-lg p-6 border border-yellow-200 dark:border-yellow-800">
            <h3 class="text-lg font-medium text-gray-900 dark:text-white mb-4 flex items-center">
                <i class="fas fa-flask text-yellow-600 dark:text-yellow-400 mr-2"></i>
                Test Email Configuration
            </h3>
            <p class="text-sm text-gray-600 dark:text-gray-400 mb-4">
                Send a test email to verify your SMTP configuration is working correctly. Make sure to save your settings first.
            </p>
            <div class="flex justify-center">
                <a href="test_email.php" class="inline-flex items-center px-6 py-3 bg-yellow-600 hover:bg-yellow-700 text-white font-medium rounded-lg transition-colors duration-200">
                    <i class="fas fa-paper-plane mr-2"></i>
                    Go to Test Email Page
                </a>
            </div>
        </div>

        <!-- Submit Button -->
        <div class="flex justify-end pt-6 border-t border-gray-200 dark:border-gray-700">
            <button type="submit" class="bg-indigo-600 hover:bg-indigo-700 text-white font-medium py-3 px-8 rounded-lg transition-colors duration-200 flex items-center">
                <i class="fas fa-save mr-2"></i>
                Save Changes
            </button>
        </div>
    </form>
</div>
