<!-- SMS Integration Tab -->
<div class="space-y-6">
    <div class="flex items-center justify-between mb-6">
        <div>
            <h2 class="text-2xl font-semibold text-gray-900 dark:text-white">SMS Integration</h2>
            <p class="text-gray-600 dark:text-gray-400 mt-1">Configure SMS gateway settings for automated notifications and alerts</p>
        </div>
    </div>

    <form method="POST" class="space-y-8">
        <input type="hidden" name="action" value="update_sms">

        <!-- SMS Gateway Selection -->
        <div>
            <h3 class="text-lg font-medium text-gray-900 dark:text-white mb-4">SMS Gateway Configuration</h3>
            <div class="space-y-6">
                <!-- Gateway Selection -->
                <div>
                    <label for="sms_gateway" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                        SMS Gateway Provider
                    </label>
                    <select id="sms_gateway" name="sms_gateway"
                        class="w-full px-4 py-3 border border-gray-300 dark:border-gray-600 rounded-lg focus:outline-none focus:ring-2 focus:ring-indigo-500 dark:bg-gray-700 dark:text-white">
                        <option value="disabled" <?php echo $settings['sms_gateway'] === 'disabled' ? 'selected' : ''; ?>>Disabled</option>
                        <optgroup label="International Providers">
                            <option value="twilio" <?php echo $settings['sms_gateway'] === 'twilio' ? 'selected' : ''; ?>>Twilio</option>
                            <option value="nexmo" <?php echo $settings['sms_gateway'] === 'nexmo' ? 'selected' : ''; ?>>Vonage (Nexmo)</option>
                            <option value="termii" <?php echo $settings['sms_gateway'] === 'termii' ? 'selected' : ''; ?>>Termii</option>
                        </optgroup>
                        <optgroup label="Ghana Providers">
                            <option value="hubtel" <?php echo $settings['sms_gateway'] === 'hubtel' ? 'selected' : ''; ?>>Hubtel</option>
                            <option value="notifysms" <?php echo $settings['sms_gateway'] === 'notifysms' ? 'selected' : ''; ?>>NotifySMS</option>
                            <option value="onlinegh" <?php echo $settings['sms_gateway'] === 'onlinegh' ? 'selected' : ''; ?>>Online GH</option>
                            <option value="wigal" <?php echo $settings['sms_gateway'] === 'wigal' ? 'selected' : ''; ?>>Wigal</option>
                            <option value="nalopay" <?php echo $settings['sms_gateway'] === 'nalopay' ? 'selected' : ''; ?>>Nalopay</option>
                        </optgroup>
                        <optgroup label="Other Providers">
                            <option value="local" <?php echo $settings['sms_gateway'] === 'local' ? 'selected' : ''; ?>>Local Provider</option>
                        </optgroup>
                    </select>
                </div>

                <!-- API Credentials -->
                <div id="sms-credentials" class="space-y-4">
                    <div>
                        <label for="sms_api_key" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                            API Key / Account SID
                        </label>
                        <input type="text" id="sms_api_key" name="sms_api_key"
                            value="<?php echo htmlspecialchars($settings['sms_api_key'] ?? ''); ?>"
                            placeholder="Enter your SMS gateway API key"
                            class="w-full px-4 py-3 border border-gray-300 dark:border-gray-600 rounded-lg focus:outline-none focus:ring-2 focus:ring-indigo-500 dark:bg-gray-700 dark:text-white">
                    </div>

                    <div>
                        <label for="sms_api_secret" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                            API Secret / Auth Token
                        </label>
                        <input type="password" id="sms_api_secret" name="sms_api_secret"
                            value="<?php echo htmlspecialchars($settings['sms_api_secret'] ?? ''); ?>"
                            placeholder="Enter your SMS gateway secret"
                            class="w-full px-4 py-3 border border-gray-300 dark:border-gray-600 rounded-lg focus:outline-none focus:ring-2 focus:ring-indigo-500 dark:bg-gray-700 dark:text-white">
                    </div>

                    <div>
                        <label for="sms_sender_id" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                            Sender ID / Phone Number
                        </label>
                        <input type="text" id="sms_sender_id" name="sms_sender_id"
                            value="<?php echo htmlspecialchars($settings['sms_sender_id'] ?? ''); ?>"
                            placeholder="e.g., SCHOOL or +1234567890"
                            class="w-full px-4 py-3 border border-gray-300 dark:border-gray-600 rounded-lg focus:outline-none focus:ring-2 focus:ring-indigo-500 dark:bg-gray-700 dark:text-white">
                        <p class="text-xs text-gray-500 dark:text-gray-400 mt-1">The name or number that appears as the sender</p>
                    </div>

                    <div>
                        <label for="sms_country_code" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                            Default Country Code
                        </label>
                        <div class="relative">
                            <span class="absolute left-4 top-1/2 -translate-y-1/2 text-gray-500 dark:text-gray-400">+</span>
                            <input type="text" id="sms_country_code" name="sms_country_code"
                                value="<?php echo htmlspecialchars($settings['sms_country_code'] ?? '233'); ?>"
                                placeholder="233"
                                class="w-full pl-8 pr-4 py-3 border border-gray-300 dark:border-gray-600 rounded-lg focus:outline-none focus:ring-2 focus:ring-indigo-500 dark:bg-gray-700 dark:text-white">
                        </div>
                        <p class="text-xs text-gray-500 dark:text-gray-400 mt-1">
                            Local numbers like <code>0244123456</code> are automatically converted to international format
                            (e.g. <code>233244123456</code>) using this code before sending. Default <code>233</code> (Ghana).
                        </p>
                    </div>
                </div>
            </div>
        </div>

        <!-- SMS Gateway Setup Guide -->
        <div class="bg-blue-50 dark:bg-blue-900/20 rounded-lg p-6 border border-blue-200 dark:border-blue-800">
            <h3 class="text-lg font-medium text-gray-900 dark:text-white mb-4 flex items-center">
                <i class="fas fa-info-circle text-blue-600 dark:text-blue-400 mr-2"></i>
                SMS Gateway Setup Guide
            </h3>
            <div class="space-y-3 text-sm text-gray-700 dark:text-gray-300">
                <div class="font-semibold text-gray-900 dark:text-white mb-2">International Providers:</div>
                <div class="flex items-start ml-4">
                    <i class="fas fa-check-circle text-green-600 dark:text-green-400 mr-2 mt-1"></i>
                    <p><strong>Twilio:</strong> From the <a href="https://console.twilio.com/" target="_blank" class="text-blue-600 hover:underline">Twilio Console</a> put your <em>Account SID</em> in the <em>API Key</em> field and your <em>Auth Token</em> in the <em>API Secret</em> field. Set <em>Sender ID</em> to your Twilio phone number in E.164 (e.g. <code>+15558675310</code>), a Messaging Service SID (<code>MG…</code>), or an approved alphanumeric sender ID. Recipient numbers are sent in E.164 automatically.</p>
                </div>
                <div class="flex items-start ml-4">
                    <i class="fas fa-check-circle text-green-600 dark:text-green-400 mr-2 mt-1"></i>
                    <p><strong>Vonage (Nexmo):</strong> Get your API Key and Secret from <a href="https://dashboard.nexmo.com/" target="_blank" class="text-blue-600 hover:underline">Vonage Dashboard</a></p>
                </div>
                <div class="flex items-start ml-4">
                    <i class="fas fa-check-circle text-green-600 dark:text-green-400 mr-2 mt-1"></i>
                    <p><strong>Termii:</strong> Get your API Key from <a href="https://termii.com/" target="_blank" class="text-blue-600 hover:underline">Termii Dashboard</a></p>
                </div>
                
                <div class="font-semibold text-gray-900 dark:text-white mt-4 mb-2">Ghana Providers:</div>
                <div class="flex items-start ml-4">
                    <i class="fas fa-check-circle text-green-600 dark:text-green-400 mr-2 mt-1"></i>
                    <p><strong>Hubtel:</strong> Get your API credentials from <a href="https://developers.hubtel.com/" target="_blank" class="text-blue-600 hover:underline">Hubtel Developers</a></p>
                </div>
                <div class="flex items-start ml-4">
                    <i class="fas fa-check-circle text-green-600 dark:text-green-400 mr-2 mt-1"></i>
                    <p><strong>NotifySMS:</strong> Contact NotifySMS for API credentials and integration details</p>
                </div>
                <div class="flex items-start ml-4">
                    <i class="fas fa-check-circle text-green-600 dark:text-green-400 mr-2 mt-1"></i>
                    <p><strong>Online GH:</strong> Get your API credentials from Online GH platform</p>
                </div>
                <div class="flex items-start ml-4">
                    <i class="fas fa-check-circle text-green-600 dark:text-green-400 mr-2 mt-1"></i>
                    <p><strong>Wigal (Frog API v3):</strong> From your <a href="https://frog.wigal.com.gh/" target="_blank" class="text-blue-600 hover:underline">Wigal Frog account</a> put your <em>API Key</em> in the <em>API Key</em> field and your <em>Wigal username</em> in the <em>API Secret</em> field. Your <em>Sender ID</em> must be an approved Wigal sender, otherwise sends fail with &ldquo;Sender ID not found&rdquo;.</p>
                </div>
                <div class="flex items-start ml-4">
                    <i class="fas fa-check-circle text-green-600 dark:text-green-400 mr-2 mt-1"></i>
                    <p><strong>Nalopay (Nalo Solutions):</strong> From your <a href="https://www.nalosolutions.com/" target="_blank" class="text-blue-600 hover:underline">Nalo portal</a> you can authenticate two ways &mdash;
                        <br>&bull; <strong>Key:</strong> paste your portal-generated API key into <em>API Key</em> and leave <em>API Secret</em> blank.
                        <br>&bull; <strong>Username/Password:</strong> put your Nalo <em>username</em> in <em>API Key</em> and your <em>password</em> in <em>API Secret</em>.
                        <br>Your <em>Sender ID</em> must be a registered/approved Nalo sender name (max 11 characters), else sends fail with &ldquo;Invalid sender&rdquo;.</p>
                </div>
                
                <div class="font-semibold text-gray-900 dark:text-white mt-4 mb-2">Other Providers:</div>
                <div class="flex items-start ml-4">
                    <i class="fas fa-check-circle text-green-600 dark:text-green-400 mr-2 mt-1"></i>
                    <p><strong>Local Provider:</strong> Contact your local SMS provider for API credentials and integration details</p>
                </div>
            </div>
        </div>

        <!-- SMS Notification Settings -->
        <div>
            <h3 class="text-lg font-medium text-gray-900 dark:text-white mb-4">SMS Notification Triggers</h3>
            <p class="text-sm text-gray-600 dark:text-gray-400 mb-4">Configure which events should trigger SMS notifications to parents and guardians</p>
            <div class="space-y-3">
                <!-- Hidden inputs to ensure unchecked boxes send '0' -->
                <input type="hidden" name="sms_absence_alerts" value="0">
                <input type="hidden" name="sms_payment_reminders" value="0">
                <input type="hidden" name="sms_exam_results" value="0">
                <input type="hidden" name="sms_event_announcements" value="0">
                <input type="hidden" name="sms_emergency_alerts" value="0">
                
                <div class="flex items-center justify-between p-4 bg-gray-50 dark:bg-gray-900 rounded-lg border border-gray-200 dark:border-gray-700">
                    <div>
                        <p class="font-medium text-gray-900 dark:text-white">Student Absence Alerts</p>
                        <p class="text-sm text-gray-600 dark:text-gray-400">Notify parents when student is marked absent</p>
                    </div>
                    <label class="relative inline-flex items-center cursor-pointer">
                        <input type="checkbox" name="sms_absence_alerts" value="1" class="sr-only peer" 
                            <?php echo (isset($settings['sms_absence_alerts']) && $settings['sms_absence_alerts'] == '1') ? 'checked' : ''; ?>>
                        <div class="w-11 h-6 bg-gray-200 peer-focus:outline-none peer-focus:ring-4 peer-focus:ring-blue-300 dark:peer-focus:ring-blue-800 rounded-full peer dark:bg-gray-700 peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-gray-300 after:border after:rounded-full after:h-5 after:w-5 after:transition-all dark:border-gray-600 peer-checked:bg-blue-600"></div>
                    </label>
                </div>

                <div class="flex items-center justify-between p-4 bg-gray-50 dark:bg-gray-900 rounded-lg border border-gray-200 dark:border-gray-700">
                    <div>
                        <p class="font-medium text-gray-900 dark:text-white">Fee Payment Reminders</p>
                        <p class="text-sm text-gray-600 dark:text-gray-400">Send payment reminders to parents</p>
                    </div>
                    <label class="relative inline-flex items-center cursor-pointer">
                        <input type="checkbox" name="sms_payment_reminders" value="1" class="sr-only peer" 
                            <?php echo (isset($settings['sms_payment_reminders']) && $settings['sms_payment_reminders'] == '1') ? 'checked' : ''; ?>>
                        <div class="w-11 h-6 bg-gray-200 peer-focus:outline-none peer-focus:ring-4 peer-focus:ring-blue-300 dark:peer-focus:ring-blue-800 rounded-full peer dark:bg-gray-700 peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-gray-300 after:border after:rounded-full after:h-5 after:w-5 after:transition-all dark:border-gray-600 peer-checked:bg-blue-600"></div>
                    </label>
                </div>

                <div class="flex items-center justify-between p-4 bg-gray-50 dark:bg-gray-900 rounded-lg border border-gray-200 dark:border-gray-700">
                    <div>
                        <p class="font-medium text-gray-900 dark:text-white">Exam Results Published</p>
                        <p class="text-sm text-gray-600 dark:text-gray-400">Notify when exam results are available</p>
                    </div>
                    <label class="relative inline-flex items-center cursor-pointer">
                        <input type="checkbox" name="sms_exam_results" value="1" class="sr-only peer" 
                            <?php echo (isset($settings['sms_exam_results']) && $settings['sms_exam_results'] == '1') ? 'checked' : ''; ?>>
                        <div class="w-11 h-6 bg-gray-200 peer-focus:outline-none peer-focus:ring-4 peer-focus:ring-blue-300 dark:peer-focus:ring-blue-800 rounded-full peer dark:bg-gray-700 peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-gray-300 after:border after:rounded-full after:h-5 after:w-5 after:transition-all dark:border-gray-600 peer-checked:bg-blue-600"></div>
                    </label>
                </div>

                <div class="flex items-center justify-between p-4 bg-gray-50 dark:bg-gray-900 rounded-lg border border-gray-200 dark:border-gray-700">
                    <div>
                        <p class="font-medium text-gray-900 dark:text-white">Event Announcements</p>
                        <p class="text-sm text-gray-600 dark:text-gray-400">Send SMS for school events and announcements</p>
                    </div>
                    <label class="relative inline-flex items-center cursor-pointer">
                        <input type="checkbox" name="sms_event_announcements" value="1" class="sr-only peer" 
                            <?php echo (isset($settings['sms_event_announcements']) && $settings['sms_event_announcements'] == '1') ? 'checked' : ''; ?>>
                        <div class="w-11 h-6 bg-gray-200 peer-focus:outline-none peer-focus:ring-4 peer-focus:ring-blue-300 dark:peer-focus:ring-blue-800 rounded-full peer dark:bg-gray-700 peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-gray-300 after:border after:rounded-full after:h-5 after:w-5 after:transition-all dark:border-gray-600 peer-checked:bg-blue-600"></div>
                    </label>
                </div>

                <div class="flex items-center justify-between p-4 bg-gray-50 dark:bg-gray-900 rounded-lg border border-gray-200 dark:border-gray-700">
                    <div>
                        <p class="font-medium text-gray-900 dark:text-white">Emergency Alerts</p>
                        <p class="text-sm text-gray-600 dark:text-gray-400">Send urgent notifications to all parents</p>
                    </div>
                    <label class="relative inline-flex items-center cursor-pointer">
                        <input type="checkbox" name="sms_emergency_alerts" value="1" class="sr-only peer" 
                            <?php echo (isset($settings['sms_emergency_alerts']) && $settings['sms_emergency_alerts'] == '1') ? 'checked' : ''; ?>>
                        <div class="w-11 h-6 bg-gray-200 peer-focus:outline-none peer-focus:ring-4 peer-focus:ring-blue-300 dark:peer-focus:ring-blue-800 rounded-full peer dark:bg-gray-700 peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-gray-300 after:border after:rounded-full after:h-5 after:w-5 after:transition-all dark:border-gray-600 peer-checked:bg-blue-600"></div>
                    </label>
                </div>
            </div>
        </div>

        <!-- Test SMS -->
        <div class="bg-yellow-50 dark:bg-yellow-900/20 rounded-lg p-6 border border-yellow-200 dark:border-yellow-800">
            <h3 class="text-lg font-medium text-gray-900 dark:text-white mb-4 flex items-center">
                <i class="fas fa-flask text-yellow-600 dark:text-yellow-400 mr-2"></i>
                Test SMS Configuration
            </h3>
            <p class="text-sm text-gray-600 dark:text-gray-400 mb-4">
                Send a test SMS to verify your configuration is working correctly. Make sure to save your settings first.
            </p>
            <div class="flex justify-center">
                <a href="test_sms.php" class="inline-flex items-center px-6 py-3 bg-yellow-600 hover:bg-yellow-700 text-white font-medium rounded-lg transition-colors duration-200">
                    <i class="fas fa-paper-plane mr-2"></i>
                    Go to Test SMS Page
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

<script>
// Show/hide SMS credentials based on gateway selection
document.getElementById('sms_gateway').addEventListener('change', function() {
    const credentialsDiv = document.getElementById('sms-credentials');
    if (this.value === 'disabled') {
        credentialsDiv.style.display = 'none';
    } else {
        credentialsDiv.style.display = 'block';
    }
});

// Initialize on page load
document.addEventListener('DOMContentLoaded', function() {
    const gateway = document.getElementById('sms_gateway').value;
    const credentialsDiv = document.getElementById('sms-credentials');
    if (gateway === 'disabled') {
        credentialsDiv.style.display = 'none';
    }
});
</script>
