<!-- Draft AI Tab -->
<div class="space-y-6">
    <div class="flex items-center justify-between mb-6">
        <div>
            <h2 class="text-2xl font-semibold text-gray-900 dark:text-white flex items-center">
                <i class="fas fa-wand-magic-sparkles text-violet-600 dark:text-violet-400 mr-2"></i>
                Draft AI
            </h2>
            <p class="text-gray-600 dark:text-gray-400 mt-1">Configure the AI assistant that helps staff draft announcements, SMS, emails and other communications</p>
        </div>
    </div>

    <form method="POST" class="space-y-8">
        <input type="hidden" name="action" value="update_ai">

        <!-- Provider Selection -->
        <div>
            <h3 class="text-lg font-medium text-gray-900 dark:text-white mb-4">AI Provider</h3>
            <div class="space-y-6">
                <div>
                    <label for="ai_provider" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                        Provider
                    </label>
                    <select id="ai_provider" name="ai_provider"
                        class="w-full px-4 py-3 border border-gray-300 dark:border-gray-600 rounded-lg focus:outline-none focus:ring-2 focus:ring-indigo-500 dark:bg-gray-700 dark:text-white">
                        <option value="builtin" <?php echo ($settings['ai_provider'] ?? 'builtin') === 'builtin' ? 'selected' : ''; ?>>Built-in assistant (free, no key required)</option>
                        <option value="gemini" <?php echo ($settings['ai_provider'] ?? '') === 'gemini' ? 'selected' : ''; ?>>Google Gemini (free tier)</option>
                    </select>
                    <p class="text-xs text-gray-500 dark:text-gray-400 mt-1">
                        The built-in assistant works offline for free. Connect Google Gemini's free tier for smarter, more natural drafts.
                    </p>
                </div>

                <!-- Gemini credentials -->
                <div id="ai-credentials" class="space-y-4">
                    <div>
                        <label for="ai_api_key" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                            API Key
                        </label>
                        <input type="password" id="ai_api_key" name="ai_api_key"
                            value="<?php echo htmlspecialchars($settings['ai_api_key'] ?? ''); ?>"
                            placeholder="Paste your Google Gemini API key"
                            class="w-full px-4 py-3 border border-gray-300 dark:border-gray-600 rounded-lg focus:outline-none focus:ring-2 focus:ring-indigo-500 dark:bg-gray-700 dark:text-white">
                        <p class="text-xs text-gray-500 dark:text-gray-400 mt-1">Stored privately for this school. Leave the provider on "Built-in" if you don't have a key.</p>
                    </div>

                    <div>
                        <label for="ai_model" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                            Model
                        </label>
                        <input type="text" id="ai_model" name="ai_model"
                            value="<?php echo htmlspecialchars($settings['ai_model'] ?? 'gemini-1.5-flash'); ?>"
                            placeholder="gemini-1.5-flash"
                            class="w-full px-4 py-3 border border-gray-300 dark:border-gray-600 rounded-lg focus:outline-none focus:ring-2 focus:ring-indigo-500 dark:bg-gray-700 dark:text-white">
                        <p class="text-xs text-gray-500 dark:text-gray-400 mt-1">Recommended free model: <code>gemini-1.5-flash</code></p>
                    </div>
                </div>
            </div>
        </div>

        <!-- Setup Guide -->
        <div class="bg-violet-50 dark:bg-violet-900/20 rounded-lg p-6 border border-violet-200 dark:border-violet-800">
            <h3 class="text-lg font-medium text-gray-900 dark:text-white mb-4 flex items-center">
                <i class="fas fa-info-circle text-violet-600 dark:text-violet-400 mr-2"></i>
                How to get a free Gemini API key
            </h3>
            <div class="space-y-3 text-sm text-gray-700 dark:text-gray-300">
                <div class="flex items-start ml-1">
                    <i class="fas fa-check-circle text-green-600 dark:text-green-400 mr-2 mt-1"></i>
                    <p>Visit <a href="https://aistudio.google.com/app/apikey" target="_blank" class="text-violet-600 hover:underline">Google AI Studio</a> and sign in with a Google account.</p>
                </div>
                <div class="flex items-start ml-1">
                    <i class="fas fa-check-circle text-green-600 dark:text-green-400 mr-2 mt-1"></i>
                    <p>Click <strong>Create API key</strong>, copy it, and paste it above.</p>
                </div>
                <div class="flex items-start ml-1">
                    <i class="fas fa-check-circle text-green-600 dark:text-green-400 mr-2 mt-1"></i>
                    <p>Set the provider to <strong>Google Gemini</strong> and save. Draft AI will appear in the Communication composer, announcements, SMS and email drafting.</p>
                </div>
            </div>
        </div>

        <!-- Where it appears -->
        <div class="bg-gray-50 dark:bg-gray-900 rounded-lg p-6 border border-gray-200 dark:border-gray-700">
            <h3 class="text-lg font-medium text-gray-900 dark:text-white mb-3">Where Draft AI helps</h3>
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3 text-sm text-gray-700 dark:text-gray-300">
                <div class="flex items-center"><i class="fas fa-bullhorn text-indigo-500 mr-2"></i> Announcements</div>
                <div class="flex items-center"><i class="fas fa-sms text-green-500 mr-2"></i> SMS messages</div>
                <div class="flex items-center"><i class="fas fa-envelope text-blue-500 mr-2"></i> Email messages</div>
                <div class="flex items-center"><i class="fas fa-comments text-violet-500 mr-2"></i> Other communication drafts</div>
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
// Show/hide Gemini credentials based on provider selection
function toggleAiCredentials() {
    const provider = document.getElementById('ai_provider').value;
    const credentialsDiv = document.getElementById('ai-credentials');
    credentialsDiv.style.display = (provider === 'builtin') ? 'none' : 'block';
}
document.getElementById('ai_provider').addEventListener('change', toggleAiCredentials);
document.addEventListener('DOMContentLoaded', toggleAiCredentials);
toggleAiCredentials();
</script>
