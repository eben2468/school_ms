<?php
/**
 * Draft AI assistant modal (reusable partial).
 *
 * Include this once on any composition page, then open it from a trigger with:
 *
 *   openDraftAI({ contentType: 'announcement', subjectField: 'title', bodyField: 'content' });
 *
 * Optionally set the endpoint before opening (defaults to 'draft_ai.php'):
 *   window.DRAFT_AI_ENDPOINT = '../draft_ai.php';
 *
 * - contentType : announcement | sms | email | general (pre-selects the type)
 * - subjectField: id of an input to receive the generated subject/title (optional)
 * - bodyField   : id of the textarea to receive the generated message body (required)
 */
if (!defined('DRAFT_AI_MODAL_LOADED')) {
    define('DRAFT_AI_MODAL_LOADED', true);
?>
<!-- Draft AI Modal -->
<div id="draftAiModal" class="fixed inset-0 bg-gray-900/60 backdrop-blur-sm hidden z-[90] overflow-y-auto">
    <div class="flex min-h-full items-start justify-center px-4 pt-20 pb-10">
        <div class="draft-ai-panel relative w-full max-w-3xl shadow-2xl rounded-2xl bg-white dark:bg-gray-800">
        <!-- Header -->
        <div class="bg-gradient-to-r from-violet-600 via-purple-600 to-indigo-600 rounded-t-2xl px-6 py-5 flex items-center justify-between">
            <div class="flex items-center text-white">
                <div class="w-10 h-10 bg-white/20 rounded-xl flex items-center justify-center mr-3">
                    <i class="fas fa-wand-magic-sparkles text-xl"></i>
                </div>
                <div>
                    <h3 class="text-lg font-bold">Draft AI</h3>
                    <p class="text-xs text-violet-100">Let AI help you draft this communication</p>
                </div>
            </div>
            <button type="button" onclick="closeDraftAI()" class="text-white/80 hover:text-white">
                <i class="fas fa-times text-xl"></i>
            </button>
        </div>

        <div class="p-6 space-y-5">
            <!-- Input form -->
            <div id="draftAiForm" class="space-y-4">
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Content Type</label>
                        <select id="draftAiType" class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg text-sm dark:bg-gray-700 dark:text-white focus:outline-none focus:ring-2 focus:ring-indigo-500">
                            <option value="announcement">Announcement</option>
                            <option value="sms">SMS Message</option>
                            <option value="email">Email Message</option>
                            <option value="general">Other / General</option>
                        </select>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Tone</label>
                        <select id="draftAiTone" class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg text-sm dark:bg-gray-700 dark:text-white focus:outline-none focus:ring-2 focus:ring-indigo-500">
                            <option value="formal">Formal</option>
                            <option value="friendly">Friendly</option>
                            <option value="informative">Informative</option>
                            <option value="urgent">Urgent</option>
                            <option value="encouraging">Encouraging</option>
                        </select>
                    </div>
                </div>

                <div>
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">
                        What is this message about? <span class="text-red-500">*</span>
                    </label>
                    <textarea id="draftAiTopic" rows="2" placeholder="e.g. Mid-term break starts next Friday and school resumes the following Monday"
                        class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg text-sm dark:bg-gray-700 dark:text-white focus:outline-none focus:ring-2 focus:ring-indigo-500"></textarea>
                </div>

                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Audience <span class="text-gray-400 text-xs">(optional)</span></label>
                        <input type="text" id="draftAiAudience" placeholder="e.g. Parents, Students, Staff"
                            class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg text-sm dark:bg-gray-700 dark:text-white focus:outline-none focus:ring-2 focus:ring-indigo-500">
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Length</label>
                        <select id="draftAiLength" class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg text-sm dark:bg-gray-700 dark:text-white focus:outline-none focus:ring-2 focus:ring-indigo-500">
                            <option value="short">Short</option>
                            <option value="medium" selected>Medium</option>
                            <option value="long">Detailed</option>
                        </select>
                    </div>
                </div>

                <div>
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Key points to include <span class="text-gray-400 text-xs">(optional, one per line)</span></label>
                    <textarea id="draftAiPoints" rows="2" placeholder="Date and time&#10;Venue&#10;What to bring"
                        class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg text-sm dark:bg-gray-700 dark:text-white focus:outline-none focus:ring-2 focus:ring-indigo-500"></textarea>
                </div>

                <button type="button" onclick="runDraftAI()" id="draftAiGenerateBtn"
                    class="w-full bg-gradient-to-r from-violet-600 to-indigo-600 hover:from-violet-700 hover:to-indigo-700 text-white font-medium py-2.5 rounded-lg transition-all duration-200 flex items-center justify-center shadow-lg">
                    <i class="fas fa-wand-magic-sparkles mr-2"></i>
                    <span>Generate Draft</span>
                </button>
            </div>

            <!-- Loading -->
            <div id="draftAiLoading" class="hidden text-center py-8">
                <i class="fas fa-circle-notch fa-spin text-3xl text-indigo-500 mb-3"></i>
                <p class="text-sm text-gray-500 dark:text-gray-400">Drafting your message...</p>
            </div>

            <!-- Error -->
            <div id="draftAiError" class="hidden bg-red-50 dark:bg-red-900/20 border border-red-200 dark:border-red-800 text-red-700 dark:text-red-300 rounded-lg px-4 py-3 text-sm flex items-center">
                <i class="fas fa-exclamation-triangle mr-2"></i>
                <span id="draftAiErrorText"></span>
            </div>

            <!-- Result preview -->
            <div id="draftAiResult" class="hidden space-y-3">
                <div class="border-t border-gray-200 dark:border-gray-700 pt-4">
                    <div class="flex items-center justify-between mb-2">
                        <h4 class="text-sm font-bold text-gray-900 dark:text-white flex items-center">
                            <i class="fas fa-file-lines text-indigo-500 mr-2"></i>Generated Draft
                        </h4>
                        <span id="draftAiSource" class="text-[10px] font-semibold uppercase px-2 py-0.5 rounded-full bg-indigo-50 dark:bg-indigo-900/40 text-indigo-600 dark:text-indigo-300"></span>
                    </div>

                    <div id="draftAiSubjectWrap" class="mb-2 hidden">
                        <label class="block text-xs font-medium text-gray-500 dark:text-gray-400 mb-1">Subject / Title</label>
                        <input type="text" id="draftAiSubjectOut"
                            class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg text-sm dark:bg-gray-900 dark:text-white focus:outline-none focus:ring-2 focus:ring-indigo-500">
                    </div>

                    <label class="block text-xs font-medium text-gray-500 dark:text-gray-400 mb-1">Message</label>
                    <textarea id="draftAiBodyOut" rows="9"
                        class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg text-sm dark:bg-gray-900 dark:text-white focus:outline-none focus:ring-2 focus:ring-indigo-500 font-mono"></textarea>

                    <p id="draftAiNote" class="text-xs text-gray-400 mt-2"></p>

                    <div class="flex flex-col sm:flex-row gap-2 mt-4">
                        <button type="button" onclick="useDraftAI()"
                            class="flex-1 bg-green-600 hover:bg-green-700 text-white font-medium py-2.5 rounded-lg transition-colors duration-200 flex items-center justify-center">
                            <i class="fas fa-check mr-2"></i>Use This Draft
                        </button>
                        <button type="button" onclick="runDraftAI()"
                            class="flex-1 bg-gray-100 hover:bg-gray-200 dark:bg-gray-700 dark:hover:bg-gray-600 text-gray-700 dark:text-gray-200 font-medium py-2.5 rounded-lg transition-colors duration-200 flex items-center justify-center">
                            <i class="fas fa-rotate mr-2"></i>Regenerate
                        </button>
                    </div>
                </div>
            </div>
        </div>
        </div>
    </div>
</div>

<script>
(function () {
    // Per-open target configuration.
    let draftAiTarget = { contentType: 'general', subjectField: null, bodyField: null };

    window.openDraftAI = function (opts) {
        opts = opts || {};
        draftAiTarget = {
            contentType: opts.contentType || 'general',
            subjectField: opts.subjectField || null,
            bodyField: opts.bodyField || null,
        };

        const typeSel = document.getElementById('draftAiType');
        if (typeSel) { typeSel.value = draftAiTarget.contentType; }

        // Reset state.
        document.getElementById('draftAiResult').classList.add('hidden');
        document.getElementById('draftAiError').classList.add('hidden');
        document.getElementById('draftAiLoading').classList.add('hidden');
        document.getElementById('draftAiForm').classList.remove('hidden');

        document.getElementById('draftAiModal').classList.remove('hidden');
    };

    window.closeDraftAI = function () {
        document.getElementById('draftAiModal').classList.add('hidden');
    };

    window.runDraftAI = function () {
        const topic = document.getElementById('draftAiTopic').value.trim();
        const errorBox = document.getElementById('draftAiError');
        const errorText = document.getElementById('draftAiErrorText');

        if (topic === '') {
            errorText.textContent = 'Please describe what the message is about.';
            errorBox.classList.remove('hidden');
            return;
        }

        errorBox.classList.add('hidden');
        document.getElementById('draftAiResult').classList.add('hidden');
        document.getElementById('draftAiLoading').classList.remove('hidden');
        document.getElementById('draftAiGenerateBtn').disabled = true;

        const endpoint = window.DRAFT_AI_ENDPOINT || 'draft_ai.php';
        const data = new URLSearchParams();
        data.append('content_type', document.getElementById('draftAiType').value);
        data.append('topic', topic);
        data.append('tone', document.getElementById('draftAiTone').value);
        data.append('audience', document.getElementById('draftAiAudience').value.trim());
        data.append('key_points', document.getElementById('draftAiPoints').value.trim());
        data.append('length', document.getElementById('draftAiLength').value);

        fetch(endpoint, {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: data.toString(),
        })
        .then(r => r.json())
        .then(res => {
            document.getElementById('draftAiLoading').classList.add('hidden');
            document.getElementById('draftAiGenerateBtn').disabled = false;

            if (!res || !res.success) {
                errorText.textContent = (res && res.message) ? res.message : 'Could not generate a draft.';
                errorBox.classList.remove('hidden');
                return;
            }

            const subjectWrap = document.getElementById('draftAiSubjectWrap');
            const subjectOut = document.getElementById('draftAiSubjectOut');
            if (res.subject) {
                subjectOut.value = res.subject;
                subjectWrap.classList.remove('hidden');
            } else {
                subjectOut.value = '';
                subjectWrap.classList.add('hidden');
            }

            document.getElementById('draftAiBodyOut').value = res.body || '';

            const sourceBadge = document.getElementById('draftAiSource');
            sourceBadge.textContent = res.source === 'ai' ? 'AI' : 'Built-in';

            document.getElementById('draftAiNote').textContent = res.note || '';
            document.getElementById('draftAiResult').classList.remove('hidden');
        })
        .catch(() => {
            document.getElementById('draftAiLoading').classList.add('hidden');
            document.getElementById('draftAiGenerateBtn').disabled = false;
            errorText.textContent = 'A network error occurred. Please try again.';
            errorBox.classList.remove('hidden');
        });
    };

    window.useDraftAI = function () {
        const subject = document.getElementById('draftAiSubjectOut').value;
        const body = document.getElementById('draftAiBodyOut').value;

        if (draftAiTarget.bodyField) {
            const bodyEl = document.getElementById(draftAiTarget.bodyField);
            if (bodyEl) {
                bodyEl.value = body;
                bodyEl.dispatchEvent(new Event('input', { bubbles: true }));
            }
        }

        if (draftAiTarget.subjectField && subject) {
            const subjEl = document.getElementById(draftAiTarget.subjectField);
            // Only fill the subject when it is currently empty so we don't clobber
            // something the user already typed.
            if (subjEl && subjEl.value.trim() === '') {
                subjEl.value = subject;
                subjEl.dispatchEvent(new Event('input', { bubbles: true }));
            }
        }

        closeDraftAI();
    };

    // Close when clicking outside the panel (the dimmed backdrop or padding area).
    document.getElementById('draftAiModal').addEventListener('click', function (e) {
        if (!e.target.closest('.draft-ai-panel')) { closeDraftAI(); }
    });
})();
</script>
<?php
}
?>
