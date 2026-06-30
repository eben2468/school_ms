<?php
require_once dirname(__DIR__, 2) . '/includes/ai_helper.php';
$__providers = aiProviderMeta();
$__currentProvider = $settings['ai_provider'] ?? 'builtin';
?>
<!-- AI & Assistant Tab -->
<div class="space-y-6">
    <div class="flex items-center justify-between mb-6">
        <div>
            <h2 class="text-2xl font-semibold text-gray-900 dark:text-white flex items-center">
                <i class="fas fa-wand-magic-sparkles text-violet-600 dark:text-violet-400 mr-2"></i>
                AI &amp; Assistant
            </h2>
            <p class="text-gray-600 dark:text-gray-400 mt-1">Configure Draft AI (staff drafting help) and the Nadics AI assistant (chat for all users). Both share the provider and key below.</p>
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
                        <?php foreach ($__providers as $pkey => $pmeta): ?>
                        <option value="<?php echo htmlspecialchars($pkey); ?>"
                            data-model="<?php echo htmlspecialchars($pmeta['default_model']); ?>"
                            data-keys="<?php echo htmlspecialchars($pmeta['keys_url']); ?>"
                            data-free="<?php echo $pmeta['free'] ? '1' : '0'; ?>"
                            <?php echo $__currentProvider === $pkey ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($pmeta['label']); ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                    <p class="text-xs text-gray-500 dark:text-gray-400 mt-1">
                        The built-in assistant works offline for free. Connect a provider below for smarter, conversational answers. Free options: Gemini, Groq, OpenRouter, Mistral, Cohere.
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
                            value="<?php echo htmlspecialchars($settings['ai_model'] ?? 'gemini-2.5-flash'); ?>"
                            placeholder="gemini-2.5-flash"
                            class="w-full px-4 py-3 border border-gray-300 dark:border-gray-600 rounded-lg focus:outline-none focus:ring-2 focus:ring-indigo-500 dark:bg-gray-700 dark:text-white">
                        <p class="text-xs text-gray-500 dark:text-gray-400 mt-1">Recommended free model: <code>gemini-2.5-flash</code></p>
                    </div>
                </div>
            </div>
        </div>

        <!-- Setup Guide: free providers and where to get keys -->
        <div class="bg-violet-50 dark:bg-violet-900/20 rounded-lg p-6 border border-violet-200 dark:border-violet-800">
            <h3 class="text-lg font-medium text-gray-900 dark:text-white mb-2 flex items-center">
                <i class="fas fa-key text-violet-600 dark:text-violet-400 mr-2"></i>
                Get a free API key
            </h3>
            <p class="text-sm text-gray-600 dark:text-gray-400 mb-4">
                Pick any provider below, create a free key, choose it in the <strong>Provider</strong> dropdown above, paste the key, and save.
                One key powers both <strong>Draft AI</strong> and the <strong>Nadics AI</strong> assistant.
            </p>
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3 text-sm">
                <?php
                $__guide = [
                    'gemini'     => ['Google Gemini', 'Generous free tier. Recommended model: gemini-2.5-flash', 'fab fa-google', 'text-blue-500'],
                    'groq'       => ['Groq (Llama 3.3 70B)', 'Free & extremely fast. Model: llama-3.3-70b-versatile', 'fas fa-bolt', 'text-orange-500'],
                    'openrouter' => ['OpenRouter', 'Many free community models (look for “:free”).', 'fas fa-route', 'text-emerald-500'],
                    'mistral'    => ['Mistral AI', 'Free tier. Model: mistral-small-latest', 'fas fa-wind', 'text-amber-500'],
                    'cohere'     => ['Cohere', 'Free trial keys. Model: command-r-08-2024', 'fas fa-feather', 'text-pink-500'],
                    'openai'     => ['OpenAI (paid)', 'Highest quality but NOT free — requires credits.', 'fas fa-circle-nodes', 'text-gray-500'],
                ];
                foreach ($__guide as $gkey => $g):
                    if (empty($__providers[$gkey]['keys_url'])) continue;
                ?>
                <a href="<?php echo htmlspecialchars($__providers[$gkey]['keys_url']); ?>" target="_blank" rel="noopener"
                   class="flex items-start p-3 rounded-lg bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 hover:border-violet-400 hover:shadow transition">
                    <i class="<?php echo $g[2]; ?> <?php echo $g[3]; ?> mt-0.5 mr-3 w-5 text-center"></i>
                    <span>
                        <span class="block font-medium text-gray-900 dark:text-white"><?php echo htmlspecialchars($g[0]); ?>
                            <i class="fas fa-arrow-up-right-from-square text-[10px] text-gray-400 ml-1"></i></span>
                        <span class="block text-xs text-gray-500 dark:text-gray-400 mt-0.5"><?php echo htmlspecialchars($g[1]); ?></span>
                    </span>
                </a>
                <?php endforeach; ?>
            </div>
            <p class="text-xs text-gray-500 dark:text-gray-400 mt-4">
                <i class="fas fa-shield-halved mr-1"></i>
                Keys are stored privately for this school. If a provider is busy or out of quota, the assistant automatically falls back to the free built-in responder.
            </p>
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

        <!-- Nadics AI Assistant -->
        <div class="border-t border-gray-200 dark:border-gray-700 pt-8">
            <h3 class="text-lg font-medium text-gray-900 dark:text-white mb-1 flex items-center">
                <i class="fas fa-robot text-indigo-600 dark:text-indigo-400 mr-2"></i>
                Nadics AI Assistant
            </h3>
            <p class="text-gray-600 dark:text-gray-400 text-sm mb-4">
                A floating chat assistant available to every user (students, teachers, parents, staff) on every page.
                It uses the same provider and key configured above, and falls back to a free built-in responder when no key is set.
            </p>
            <a href="/school_ms/admin/nadics_ai_logs.php"
               class="inline-flex items-center text-sm text-indigo-600 dark:text-indigo-400 hover:underline mb-5">
                <i class="fas fa-clipboard-list mr-2"></i> View AI interaction logs
            </a>

            <div class="space-y-6">
                <!-- Enable toggle -->
                <div class="flex items-center justify-between p-4 rounded-lg bg-gray-50 dark:bg-gray-900 border border-gray-200 dark:border-gray-700">
                    <div>
                        <span class="block text-sm font-medium text-gray-900 dark:text-white">Enable Nadics AI</span>
                        <span class="block text-xs text-gray-500 dark:text-gray-400">Show the assistant launcher across the system.</span>
                    </div>
                    <label class="relative inline-flex items-center cursor-pointer">
                        <input type="checkbox" name="nadics_enabled" value="1" class="sr-only peer"
                            <?php echo (($settings['nadics_enabled'] ?? '1') === '0') ? '' : 'checked'; ?>>
                        <div class="w-11 h-6 bg-gray-200 peer-focus:outline-none peer-focus:ring-4 peer-focus:ring-blue-300 dark:peer-focus:ring-blue-800 rounded-full peer dark:bg-gray-700 peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-gray-300 after:border after:rounded-full after:h-5 after:w-5 after:transition-all dark:border-gray-600 peer-checked:bg-blue-600"></div>
                    </label>
                </div>

                <!-- Assistant name (only super admins may change it) -->
                <?php $can_edit_name = !empty($is_super); ?>
                <div>
                    <label for="nadics_name" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Assistant Name</label>
                    <input type="text" id="nadics_name" name="nadics_name"
                        value="<?php echo htmlspecialchars($settings['nadics_name'] ?? 'Nadics AI'); ?>"
                        placeholder="Nadics AI"
                        <?php echo $can_edit_name ? '' : 'disabled'; ?>
                        class="w-full px-4 py-3 border border-gray-300 dark:border-gray-600 rounded-lg focus:outline-none focus:ring-2 focus:ring-indigo-500 dark:bg-gray-700 dark:text-white <?php echo $can_edit_name ? '' : 'opacity-60 cursor-not-allowed bg-gray-100 dark:bg-gray-900'; ?>">
                    <p class="text-xs text-gray-500 dark:text-gray-400 mt-1">
                        Shown in the chat header and greeting.<?php echo $can_edit_name ? '' : ' Only a super admin can change the assistant name.'; ?>
                    </p>
                </div>

                <!-- Persona / extra instructions -->
                <div>
                    <label for="nadics_persona" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Behaviour &amp; Tone (optional)</label>
                    <textarea id="nadics_persona" name="nadics_persona" rows="3"
                        placeholder="e.g. Be warm and encouraging. Always remind users to contact the school office for official confirmations."
                        class="w-full px-4 py-3 border border-gray-300 dark:border-gray-600 rounded-lg focus:outline-none focus:ring-2 focus:ring-indigo-500 dark:bg-gray-700 dark:text-white"><?php echo htmlspecialchars($settings['nadics_persona'] ?? ''); ?></textarea>
                    <p class="text-xs text-gray-500 dark:text-gray-400 mt-1">Extra instructions added to the assistant's system prompt to customise how it responds.</p>
                </div>
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
(function () {
    const providerSel = document.getElementById('ai_provider');
    const credentialsDiv = document.getElementById('ai-credentials');
    const modelInput = document.getElementById('ai_model');
    const keyInput = document.getElementById('ai_api_key');

    function currentOption() {
        return providerSel.options[providerSel.selectedIndex];
    }

    function syncProviderUI(autofillModel) {
        const provider = providerSel.value;
        const opt = currentOption();
        // Credentials are needed for every provider except the offline built-in one.
        credentialsDiv.style.display = (provider === 'builtin') ? 'none' : 'block';

        const defModel = opt ? (opt.getAttribute('data-model') || '') : '';
        if (modelInput) {
            modelInput.placeholder = defModel || 'model name';
            // Auto-fill the recommended model when switching provider (or when empty),
            // so a leftover model from another provider isn't used by mistake.
            if (autofillModel && defModel) {
                modelInput.value = defModel;
            } else if (!modelInput.value && defModel) {
                modelInput.value = defModel;
            }
        }
        if (keyInput) {
            keyInput.placeholder = (provider === 'builtin')
                ? 'No key needed'
                : 'Paste your ' + (opt ? opt.textContent.trim().split(' (')[0].split(' —')[0] : '') + ' API key';
        }
    }

    providerSel.addEventListener('change', function () { syncProviderUI(true); });
    document.addEventListener('DOMContentLoaded', function () { syncProviderUI(false); });
    syncProviderUI(false);
})();
</script>
