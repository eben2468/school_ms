<?php
/**
 * Nadics AI floating chat widget.
 * --------------------------------------------------------------------------
 * Included once from includes/footer.php so it appears on every authenticated
 * page across all roles. Renders nothing when no user is signed in or when the
 * assistant has been disabled in Settings > AI Assistant.
 *
 * The chat posts to /school_ms/chat/nadics_ai.php; the CSRF token is attached
 * automatically by the same-origin fetch wrapper defined in footer.php.
 */

if (!isset($_SESSION['user_id'])) {
    return;
}

require_once __DIR__ . '/ai_helper.php';
$__nadics = getNadicsConfig();
if (($__nadics['nadics_enabled'] ?? '1') === '0') {
    return;
}
$__nadicsRawName = $__nadics['nadics_name'] ?: 'Nadics AI';
$__nadicsName = htmlspecialchars($__nadicsRawName, ENT_QUOTES);       // for HTML text/attributes
$__nadicsNameJs = json_encode($__nadicsRawName, JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_TAG); // for <script> contexts
// Lift the launcher above the super-admin impersonation banner when present.
$__nadicsBottom = !empty($_SESSION['impersonator']) ? '4.75rem' : '1.5rem';
?>
<div id="nadics-ai-widget" x-data="nadicsAI()" x-cloak
     style="position: fixed; right: 1.5rem; bottom: <?php echo $__nadicsBottom; ?>; z-index: 58;">

    <!-- Chat panel -->
    <div x-show="open" x-transition
         class="mb-3 bg-white dark:bg-gray-800 rounded-2xl shadow-2xl border border-gray-200 dark:border-gray-700 flex flex-col overflow-hidden"
         style="width: 22rem; max-width: calc(100vw - 2rem); height: 30rem; max-height: calc(100vh - 8rem);" @click.outside="/* keep open */">

        <!-- Header -->
        <div class="flex items-center justify-between px-4 py-3 text-white" style="background: var(--primary-gradient, linear-gradient(135deg,#4f46e5,#7c3aed));">
            <div class="flex items-center space-x-2">
                <div class="w-8 h-8 rounded-full bg-white/20 flex items-center justify-center">
                    <i class="fas fa-robot text-sm"></i>
                </div>
                <div>
                    <p class="text-sm font-semibold leading-tight"><?php echo $__nadicsName; ?></p>
                    <p class="opacity-80 leading-tight" style="font-size:10px;">School assistant</p>
                </div>
            </div>
            <div class="flex items-center space-x-1">
                <button @click="clearChat()" title="Clear conversation" class="p-1.5 rounded-lg hover:bg-white/15 transition-colors">
                    <i class="fas fa-rotate-left text-xs"></i>
                </button>
                <button @click="open = false" title="Close" class="p-1.5 rounded-lg hover:bg-white/15 transition-colors">
                    <i class="fas fa-chevron-down text-xs"></i>
                </button>
            </div>
        </div>

        <!-- Messages -->
        <div x-ref="scroll" class="flex-1 overflow-y-auto px-3 py-3 space-y-3 bg-gray-50 dark:bg-gray-900/40">
            <template x-for="(m, i) in messages" :key="i">
                <div :class="m.role === 'user' ? 'flex justify-end' : 'flex justify-start'">
                    <div :class="m.role === 'user'
                            ? 'bg-indigo-600 text-white rounded-2xl rounded-br-sm'
                            : 'bg-white dark:bg-gray-800 text-gray-800 dark:text-gray-100 border border-gray-200 dark:border-gray-700 rounded-2xl rounded-bl-sm'"
                         class="px-3 py-2 text-sm leading-relaxed shadow-sm" style="max-width:85%;"
                         x-html="m.html"></div>
                </div>
            </template>

            <!-- Typing indicator -->
            <div x-show="loading" class="flex justify-start">
                <div class="bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 rounded-2xl rounded-bl-sm px-4 py-3">
                    <span class="nadics-dot"></span><span class="nadics-dot"></span><span class="nadics-dot"></span>
                </div>
            </div>
        </div>

        <!-- Quick suggestions (first run only) -->
        <div x-show="messages.length <= 1" class="px-3 pb-1 flex flex-wrap gap-1.5">
            <template x-for="s in suggestions" :key="s">
                <button @click="sendQuick(s)" type="button"
                        class="px-2 py-1 rounded-full bg-indigo-50 dark:bg-indigo-900/30 text-indigo-700 dark:text-indigo-300 hover:bg-indigo-100 dark:hover:bg-indigo-900/50 transition-colors"
                        style="font-size:11px;" x-text="s"></button>
            </template>
        </div>

        <!-- Composer -->
        <form @submit.prevent="send()" class="p-2.5 border-t border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800">
            <div class="flex items-end space-x-2">
                <textarea x-ref="input" x-model="draft" rows="1" :disabled="loading"
                    @keydown.enter.prevent="if(!$event.shiftKey){ send(); }"
                    placeholder="Ask me anything about the system…"
                    class="flex-1 resize-none max-h-24 px-3 py-2 text-sm rounded-xl border border-gray-300 dark:border-gray-600 bg-gray-50 dark:bg-gray-700 dark:text-white focus:outline-none focus:ring-2 focus:ring-indigo-500"></textarea>
                <button type="submit" :disabled="loading || draft.trim() === ''"
                    class="w-9 h-9 flex-shrink-0 rounded-xl bg-indigo-600 hover:bg-indigo-700 disabled:opacity-50 text-white flex items-center justify-center transition-colors">
                    <i class="fas fa-paper-plane text-xs"></i>
                </button>
            </div>
            <p class="text-gray-400 dark:text-gray-500 mt-1 text-center" style="font-size:10px;">
                <?php echo $__nadicsName; ?> can make mistakes. Verify important information.
            </p>
        </form>
    </div>

    <!-- Launcher button -->
    <button @click="toggle()" x-show="!open"
            class="w-14 h-14 rounded-full shadow-2xl text-white flex items-center justify-center hover:scale-105 transition-transform"
            style="background: var(--primary-gradient, linear-gradient(135deg,#4f46e5,#7c3aed));"
            title="Chat with <?php echo $__nadicsName; ?>">
        <i class="fas fa-robot text-xl"></i>
    </button>
    <button @click="open = false" x-show="open"
            class="w-14 h-14 rounded-full shadow-2xl bg-gray-700 text-white flex items-center justify-center hover:scale-105 transition-transform ml-auto"
            title="Close">
        <i class="fas fa-times text-xl"></i>
    </button>
</div>

<style>
    [x-cloak] { display: none !important; }
    #nadics-ai-widget .nadics-dot {
        display: inline-block; width: 6px; height: 6px; margin: 0 2px;
        background: #9ca3af; border-radius: 9999px; animation: nadicsBlink 1.2s infinite ease-in-out both;
    }
    #nadics-ai-widget .nadics-dot:nth-child(2) { animation-delay: 0.2s; }
    #nadics-ai-widget .nadics-dot:nth-child(3) { animation-delay: 0.4s; }
    @keyframes nadicsBlink { 0%, 80%, 100% { opacity: 0.3; } 40% { opacity: 1; } }
    @media print { #nadics-ai-widget { display: none !important; } }
</style>

<script>
function nadicsAI() {
    return {
        open: false,
        draft: '',
        loading: false,
        messages: [],
        suggestions: ['How do I check my grades?', 'Where do I see attendance?', 'How do I update my profile?'],
        endpoint: '/school_ms/chat/nadics_ai.php',
        storeKey: 'nadics_ai_history',

        init() {
            try {
                const saved = JSON.parse(sessionStorage.getItem(this.storeKey) || '[]');
                if (Array.isArray(saved) && saved.length) {
                    this.messages = saved;
                }
            } catch (e) {}
            if (this.messages.length === 0) {
                this.pushAssistant("Hi! I'm " + <?php echo $__nadicsNameJs; ?> + ", your school assistant. Ask me about grades, attendance, fees, the timetable, the library, or how to do something here.");
            }
        },

        toggle() {
            this.open = !this.open;
            if (this.open) {
                this.$nextTick(() => { this.scrollDown(); this.$refs.input?.focus(); });
            }
        },

        escapeHtml(s) {
            const d = document.createElement('div');
            d.textContent = s;
            return d.innerHTML;
        },

        // Escape first (XSS-safe), then apply a small, safe subset of Markdown:
        // headings, bold, italics, inline code, bullet/numbered lists, line breaks.
        format(text) {
            let h = this.escapeHtml(text);
            // Headings (#, ##, ### ...) -> bold line
            h = h.replace(/^[ \t]*#{1,6}[ \t]*(.+)$/gm, '<strong>$1</strong>');
            // Bold (**text**) before italics so the inner * aren't mis-parsed
            h = h.replace(/\*\*([^*]+?)\*\*/g, '<strong>$1</strong>');
            // Inline code `code`
            h = h.replace(/`([^`]+?)`/g, '<code class="px-1 py-0.5 rounded bg-black/10 dark:bg-white/15" style="font-size:0.85em;">$1</code>');
            // Italics: *text* and _text_ (not touching list markers or mid-word _)
            h = h.replace(/(^|[^\w*])\*(?!\s)([^*\n]+?)\*(?![\w*])/g, '$1<em>$2</em>');
            h = h.replace(/(^|[^\w_])_(?!\s)([^_\n]+?)_(?![\w_])/g, '$1<em>$2</em>');
            // Bullet list markers (- or *) at line start -> •
            h = h.replace(/^[ \t]*[-*][ \t]+/gm, '&bull; ');
            // Line breaks
            h = h.replace(/\n/g, '<br>');
            return h;
        },

        pushUser(text) {
            this.messages.push({ role: 'user', text: text, html: this.format(text) });
            this.persist();
        },
        pushAssistant(text) {
            this.messages.push({ role: 'assistant', text: text, html: this.format(text) });
            this.persist();
        },

        persist() {
            try { sessionStorage.setItem(this.storeKey, JSON.stringify(this.messages.slice(-30))); } catch (e) {}
            this.$nextTick(() => this.scrollDown());
        },

        scrollDown() {
            const el = this.$refs.scroll;
            if (el) el.scrollTop = el.scrollHeight;
        },

        clearChat() {
            this.messages = [];
            try { sessionStorage.removeItem(this.storeKey); } catch (e) {}
            this.pushAssistant("Conversation cleared. How can I help?");
        },

        sendQuick(text) {
            this.draft = text;
            this.send();
        },

        async send() {
            const text = this.draft.trim();
            if (text === '' || this.loading) return;
            this.draft = '';
            this.pushUser(text);
            this.loading = true;

            // Send the prior turns (exclude the message we just added).
            const history = this.messages.slice(0, -1).slice(-10).map(m => ({ role: m.role, text: m.text }));

            try {
                const res = await fetch(this.endpoint, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ message: text, history: history })
                });
                const data = await res.json().catch(() => ({}));
                this.loading = false;
                if (data && data.reply) {
                    this.pushAssistant(data.reply);
                } else {
                    this.pushAssistant("Sorry, I couldn't respond just now. Please try again.");
                }
            } catch (e) {
                this.loading = false;
                this.pushAssistant("I couldn't reach the server. Please check your connection and try again.");
            }
        }
    };
}
</script>
