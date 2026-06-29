<?php
/**
 * Draft AI Helper
 * --------------------------------------------------------------------------
 * Generates draft communication content (announcements, SMS, email and other
 * general messages) to assist users while composing.
 *
 * It supports a configurable, free AI provider (Google Gemini free tier) and
 * always ships with a built-in offline template generator so the feature works
 * for free out of the box even when no API key is configured.
 *
 * Configuration lives in the school_settings table (ai_provider, ai_api_key,
 * ai_model) and is managed from Settings > Draft AI. Missing columns are
 * tolerated gracefully and the helper simply falls back to the built-in mode.
 */

if (!function_exists('getAIConfig')) {
    /**
     * Read AI configuration (and a couple of school details used for signing
     * drafts) from school_settings. Always returns a usable array.
     *
     * @return array
     */
    function getAIConfig() {
        require_once __DIR__ . '/../config/database.php';
        $database = new Database();
        $db = $database->getConnection();

        $config = [
            'ai_provider' => 'builtin',
            'ai_api_key'  => '',
            'ai_model'    => 'gemini-2.5-flash',
            'school_name' => 'our school',
        ];

        try {
            $stmt = $db->query("SELECT * FROM school_settings LIMIT 1");
            $row = $stmt ? $stmt->fetch(PDO::FETCH_ASSOC) : null;
            if ($row) {
                if (!empty($row['ai_provider'])) { $config['ai_provider'] = $row['ai_provider']; }
                if (isset($row['ai_api_key']))   { $config['ai_api_key']  = $row['ai_api_key']; }
                if (!empty($row['ai_model']))    { $config['ai_model']    = $row['ai_model']; }
                if (!empty($row['school_name'])) { $config['school_name'] = $row['school_name']; }
            }
        } catch (PDOException $e) {
            // Columns or table may not exist yet - stay on built-in defaults.
            error_log("Draft AI config read failed: " . $e->getMessage());
        }

        return $config;
    }
}

if (!function_exists('generateAIDraft')) {
    /**
     * Generate a communication draft.
     *
     * @param string $type   One of: announcement, sms, email, general
     * @param array  $params topic, tone, audience, key_points, length
     * @return array { success, subject, body, source, note }
     */
    function generateAIDraft($type, $params) {
        $allowed = ['announcement', 'sms', 'email', 'general'];
        if (!in_array($type, $allowed, true)) {
            $type = 'general';
        }

        $config   = getAIConfig();
        $provider = strtolower($config['ai_provider'] ?? 'builtin');
        $apiKey   = trim($config['ai_api_key'] ?? '');
        $meta     = function_exists('aiProviderMeta') ? aiProviderMeta() : [];

        if ($provider !== 'builtin' && $apiKey !== '' && isset($meta[$provider])) {
            $model = aiResolveModel($provider, $config['ai_model'] ?? '', $meta);
            if ($meta[$provider]['type'] === 'gemini') {
                $res = generateGeminiDraft($type, $params, array_merge($config, ['ai_model' => $model]));
            } else { // OpenAI-compatible providers
                $res = generateOpenAICompatDraft($type, $params, $config, $meta[$provider], $model);
            }
            if (!empty($res['success'])) {
                return $res;
            }
            // Provider failed - degrade gracefully to the built-in generator.
            $tpl = generateTemplateDraft($type, $params, $config);
            $tpl['note'] = 'AI provider unavailable (' . ($res['message'] ?? 'unknown error')
                . '). Showing a built-in draft instead.';
            return $tpl;
        }

        return generateTemplateDraft($type, $params, $config);
    }
}

if (!function_exists('generateOpenAICompatDraft')) {
    /**
     * Draft generation via any OpenAI-compatible Chat Completions endpoint
     * (Groq, OpenRouter, Mistral, Cohere, OpenAI). Mirrors generateGeminiDraft:
     * asks for a JSON {subject, body} object and parses it leniently.
     */
    function generateOpenAICompatDraft($type, $params, $config, array $meta, $model) {
        $apiKey   = trim($config['ai_api_key']);
        $endpoint = $meta['endpoint'];
        $prompt   = buildAIPrompt($type, $params, $config);

        $payload = [
            'model'       => $model,
            'messages'    => [
                ['role' => 'system', 'content' => 'You are Draft AI, a helpful school communications assistant. Always reply with only the requested JSON object.'],
                ['role' => 'user', 'content' => $prompt],
            ],
            'temperature' => 0.7,
            'max_tokens'  => 1024,
        ];

        $headers = [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $apiKey,
        ];
        if (strpos($endpoint, 'openrouter.ai') !== false) {
            $headers[] = 'HTTP-Referer: https://school-ms.local';
            $headers[] = 'X-Title: Draft AI';
        }

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $endpoint);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 40);

        $result   = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr  = curl_error($ch);
        curl_close($ch);

        if ($curlErr) {
            return ['success' => false, 'message' => 'Connection error: ' . $curlErr];
        }

        $response = json_decode($result, true);
        if ($httpCode !== 200) {
            $apiMsg = $response['error']['message'] ?? ('HTTP ' . $httpCode);
            return ['success' => false, 'message' => $apiMsg];
        }

        $text = $response['choices'][0]['message']['content'] ?? '';
        if (trim((string)$text) === '') {
            return ['success' => false, 'message' => 'Empty response from AI provider'];
        }

        $subject = '';
        $body    = trim($text);
        if (preg_match('/\{.*\}/s', $text, $m)) {
            $parsed = json_decode($m[0], true);
            if (is_array($parsed)) {
                $subject = trim($parsed['subject'] ?? '');
                $body    = trim($parsed['body'] ?? $body);
            }
        }
        if ($type === 'sms') { $subject = ''; }

        return [
            'success' => true,
            'subject' => $subject,
            'body'    => $body,
            'source'  => 'ai',
            'note'    => 'Generated with AI. Please review before sending.',
        ];
    }
}

if (!function_exists('generateGeminiDraft')) {
    /**
     * Generate a draft using Google Gemini's free-tier generateContent API.
     */
    function generateGeminiDraft($type, $params, $config) {
        $apiKey = trim($config['ai_api_key']);
        $model  = $config['ai_model'] ?: 'gemini-2.5-flash';

        $endpoint = 'https://generativelanguage.googleapis.com/v1beta/models/'
            . rawurlencode($model) . ':generateContent?key=' . urlencode($apiKey);

        $prompt = buildAIPrompt($type, $params, $config);

        $payload = [
            'contents' => [[
                'parts' => [['text' => $prompt]],
            ]],
            'generationConfig' => [
                'temperature'     => 0.7,
                'maxOutputTokens' => 1024,
            ],
        ];

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $endpoint);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);

        $result   = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr  = curl_error($ch);
        curl_close($ch);

        if ($curlErr) {
            return ['success' => false, 'message' => 'Connection error: ' . $curlErr];
        }

        $response = json_decode($result, true);

        if ($httpCode !== 200) {
            $apiMsg = $response['error']['message'] ?? ('HTTP ' . $httpCode);
            return ['success' => false, 'message' => $apiMsg];
        }

        $text = $response['candidates'][0]['content']['parts'][0]['text'] ?? '';
        if (trim($text) === '') {
            return ['success' => false, 'message' => 'Empty response from AI provider'];
        }

        // The model is asked to reply with a JSON object {subject, body}; parse
        // it leniently and fall back to using the raw text as the body.
        $subject = '';
        $body    = trim($text);

        if (preg_match('/\{.*\}/s', $text, $m)) {
            $parsed = json_decode($m[0], true);
            if (is_array($parsed)) {
                $subject = trim($parsed['subject'] ?? '');
                $body    = trim($parsed['body'] ?? $body);
            }
        }

        if ($type === 'sms') {
            $subject = '';
        }

        return [
            'success' => true,
            'subject' => $subject,
            'body'    => $body,
            'source'  => 'ai',
            'note'    => 'Generated with AI. Please review before sending.',
        ];
    }
}

if (!function_exists('buildAIPrompt')) {
    /**
     * Build the instruction prompt sent to the AI provider.
     */
    function buildAIPrompt($type, $params, $config) {
        $topic     = trim($params['topic'] ?? '');
        $tone      = trim($params['tone'] ?? 'formal');
        $audience  = trim($params['audience'] ?? '');
        $keyPoints = trim($params['key_points'] ?? '');
        $length    = trim($params['length'] ?? 'medium');
        $school    = $config['school_name'] ?? 'our school';

        $typeLabels = [
            'announcement' => 'a school announcement to broadcast to the school community',
            'sms'          => 'a short SMS text message (keep it under 300 characters, concise and clear)',
            'email'        => 'a formal email message',
            'general'      => 'a general communication message',
        ];
        $what = $typeLabels[$type] ?? $typeLabels['general'];

        $lines = [];
        $lines[] = "You are Draft AI, an assistant that helps school staff write clear, professional communications.";
        $lines[] = "Write {$what} for {$school}.";
        $lines[] = "Topic / purpose: {$topic}";
        if ($audience !== '')  { $lines[] = "Intended audience: {$audience}"; }
        if ($keyPoints !== '') { $lines[] = "Key points to include: {$keyPoints}"; }
        $lines[] = "Desired tone: {$tone}.";
        $lines[] = "Desired length: {$length}.";

        if ($type === 'sms') {
            $lines[] = "Respond ONLY with a JSON object of the form {\"subject\":\"\",\"body\":\"...\"} where body is the SMS text (no subject needed).";
        } else {
            $lines[] = "Respond ONLY with a JSON object of the form {\"subject\":\"a concise subject/title\",\"body\":\"the full message text\"}.";
        }
        $lines[] = "Do not include markdown code fences or any text outside the JSON object.";

        return implode("\n", $lines);
    }
}

if (!function_exists('generateTemplateDraft')) {
    /**
     * Built-in offline draft generator. Produces a structured, ready-to-edit
     * draft from the supplied parameters without any external service.
     */
    function generateTemplateDraft($type, $params, $config = null) {
        if ($config === null) { $config = getAIConfig(); }

        $topic     = trim($params['topic'] ?? '');
        $tone      = strtolower(trim($params['tone'] ?? 'formal'));
        $audience  = trim($params['audience'] ?? '');
        $keyPoints = trim($params['key_points'] ?? '');
        $school    = $config['school_name'] ?? 'our school';

        $audienceLabel = $audience !== '' ? $audience : 'Everyone';
        $greetingName  = $audience !== '' ? $audience : 'all';

        // Parse key points into a clean list (split on new lines, commas or ';').
        $points = [];
        if ($keyPoints !== '') {
            foreach (preg_split('/[\n;,]+/', $keyPoints) as $p) {
                $p = trim($p);
                if ($p !== '') { $points[] = $p; }
            }
        }

        // Tone-driven opening / closing phrases.
        $openings = [
            'formal'      => 'We would like to inform you that',
            'friendly'    => 'We are excited to share that',
            'informative' => 'Please be informed that',
            'urgent'      => 'Please take note of the following important update:',
            'encouraging' => 'We are pleased to let you know that',
        ];
        $closings = [
            'formal'      => 'Thank you for your kind attention and continued support.',
            'friendly'    => 'Thank you, and we look forward to your participation!',
            'informative' => 'Please reach out to the school office if you have any questions.',
            'urgent'      => 'Your prompt attention to this matter is greatly appreciated.',
            'encouraging' => 'Thank you for your continued support of our school community.',
        ];
        $opening = $openings[$tone] ?? $openings['formal'];
        $closing = $closings[$tone] ?? $closings['formal'];

        // Subject / title.
        $subject = $topic !== ''
            ? ucfirst(rtrim($topic, '.'))
            : 'Message from ' . $school;
        if (mb_strlen($subject) > 90) {
            $subject = mb_substr($subject, 0, 87) . '...';
        }

        // ---- SMS: single concise line, character conscious ----
        if ($type === 'sms') {
            $sms = $school . ': ';
            $sms .= $topic !== '' ? rtrim($topic, '.') . '.' : 'Please see the latest update.';
            if (!empty($points)) {
                $sms .= ' ' . implode('. ', array_slice($points, 0, 2)) . '.';
            }
            if (mb_strlen($sms) > 300) {
                $sms = mb_substr($sms, 0, 297) . '...';
            }
            return [
                'success' => true,
                'subject' => '',
                'body'    => $sms,
                'source'  => 'template',
                'note'    => 'Built-in draft. Add an AI provider key in Settings > Draft AI for smarter drafts.',
            ];
        }

        // ---- Announcement / Email / General ----
        $body  = "Dear {$greetingName},\n\n";
        $body .= $opening . ' ';
        $body .= $topic !== '' ? rtrim($topic, '.') . '.' : 'we have an update to share with you.';
        $body .= "\n\n";

        if (!empty($points)) {
            $body .= "Details:\n";
            foreach ($points as $p) {
                $body .= "  • " . rtrim($p, '.') . "\n";
            }
            $body .= "\n";
        }

        $body .= $closing . "\n\n";

        if ($type === 'email' || $type === 'general') {
            $body .= "Best regards,\n" . $school;
        } else { // announcement
            $body .= "— " . $school . " Administration";
        }

        return [
            'success' => true,
            'subject' => $subject,
            'body'    => $body,
            'source'  => 'template',
            'note'    => 'Built-in draft. Add an AI provider key in Settings > Draft AI for smarter drafts.',
        ];
    }
}

/* ==========================================================================
 * Nadics AI — conversational assistant for all users
 * --------------------------------------------------------------------------
 * A role-aware chat assistant embedded across the dashboards. It reuses the
 * same free AI provider configuration as Draft AI (Google Gemini free tier)
 * and degrades gracefully to a built-in, offline rule-based responder so the
 * assistant keeps working for free even without an API key.
 *
 * Privacy: the caller (chat/nadics_ai.php) only ever assembles context from
 * the requesting user's own, role-permitted data and passes it here. This
 * helper never queries the database for user data itself.
 * ======================================================================== */

if (!function_exists('aiProviderMeta')) {
    /**
     * Registry of supported AI providers. Every entry except Gemini and the
     * built-in responder speaks the OpenAI-compatible Chat Completions format,
     * so a single client (aiOpenAICompatRequest) handles all of them.
     *
     * Keys: label, type ('builtin'|'gemini'|'openai'), endpoint, default_model,
     * keys_url (where to obtain a free API key), free (is there a free tier).
     *
     * @return array
     */
    function aiProviderMeta() {
        return [
            'builtin' => [
                'label' => 'Built-in assistant (free, no key)',
                'type' => 'builtin', 'endpoint' => '', 'default_model' => '',
                'keys_url' => '', 'free' => true,
            ],
            'gemini' => [
                'label' => 'Google Gemini (free tier)',
                'type' => 'gemini', 'endpoint' => '', 'default_model' => 'gemini-2.5-flash',
                'keys_url' => 'https://aistudio.google.com/app/apikey', 'free' => true,
            ],
            'groq' => [
                'label' => 'Groq — Llama 3.3 70B (free, very fast)',
                'type' => 'openai', 'endpoint' => 'https://api.groq.com/openai/v1/chat/completions',
                'default_model' => 'llama-3.3-70b-versatile',
                'keys_url' => 'https://console.groq.com/keys', 'free' => true,
            ],
            'openrouter' => [
                'label' => 'OpenRouter (free community models)',
                'type' => 'openai', 'endpoint' => 'https://openrouter.ai/api/v1/chat/completions',
                'default_model' => 'meta-llama/llama-3.3-70b-instruct:free',
                'keys_url' => 'https://openrouter.ai/keys', 'free' => true,
            ],
            'mistral' => [
                'label' => 'Mistral AI (free tier)',
                'type' => 'openai', 'endpoint' => 'https://api.mistral.ai/v1/chat/completions',
                'default_model' => 'mistral-small-latest',
                'keys_url' => 'https://console.mistral.ai/api-keys', 'free' => true,
            ],
            'cohere' => [
                'label' => 'Cohere — Command (free trial keys)',
                'type' => 'openai', 'endpoint' => 'https://api.cohere.ai/compatibility/v1/chat/completions',
                'default_model' => 'command-r-08-2024',
                'keys_url' => 'https://dashboard.cohere.com/api-keys', 'free' => true,
            ],
            'openai' => [
                'label' => 'OpenAI — GPT (paid)',
                'type' => 'openai', 'endpoint' => 'https://api.openai.com/v1/chat/completions',
                'default_model' => 'gpt-4o-mini',
                'keys_url' => 'https://platform.openai.com/api-keys', 'free' => false,
            ],
        ];
    }
}

if (!function_exists('aiResolveModel')) {
    /**
     * Pick the model to use for a provider. Falls back to the provider's default
     * when the configured model is empty or obviously belongs to another
     * provider (e.g. a leftover "gemini-*" name selected with Groq).
     */
    function aiResolveModel($provider, $model, array $meta) {
        $model = trim((string)$model);
        $default = $meta[$provider]['default_model'] ?? '';
        if ($model === '') {
            return $default;
        }
        // Retired/quota-disabled Gemini model names → current free flash model.
        // (gemini-2.0-flash and the 1.5-* family return a free-tier "limit: 0"
        // error on many accounts; gemini-2.5-flash works on the same key.)
        $deprecated = [
            'gemini-2.0-flash'     => 'gemini-2.5-flash',
            'gemini-1.5-flash'     => 'gemini-2.5-flash',
            'gemini-1.5-flash-8b'  => 'gemini-2.5-flash',
            'gemini-1.5-pro'       => 'gemini-2.5-flash',
            'gemini-pro'           => 'gemini-2.5-flash',
        ];
        if ($provider === 'gemini' && isset($deprecated[strtolower($model)])) {
            return $deprecated[strtolower($model)];
        }
        $isGeminiModel = stripos($model, 'gemini') !== false;
        if ($provider === 'gemini' && !$isGeminiModel) {
            return $default;
        }
        if ($provider !== 'gemini' && $isGeminiModel) {
            return $default; // stale gemini model selected for a non-gemini provider
        }
        return $model;
    }
}

if (!function_exists('getNadicsConfig')) {
    /**
     * Read Nadics AI configuration from school_settings. Reuses the shared AI
     * provider/key/model and adds Nadics-specific behaviour columns. Always
     * returns a usable array even when columns are missing.
     *
     * @return array
     */
    function getNadicsConfig() {
        $config = [
            'ai_provider'   => 'builtin',
            'ai_api_key'    => '',
            'ai_model'      => 'gemini-2.5-flash',
            'school_name'   => 'our school',
            'nadics_enabled'=> '1',
            'nadics_name'   => 'Nadics AI',
            'nadics_persona'=> '',
        ];

        try {
            require_once __DIR__ . '/../config/database.php';
            $database = new Database();
            $db = $database->getConnection();
            // getConnection() returns null on failure — guard so we never call a
            // method on null (which would be a fatal Error, not a PDOException).
            if ($db) {
                $stmt = $db->query("SELECT * FROM school_settings LIMIT 1");
                $row = $stmt ? $stmt->fetch(PDO::FETCH_ASSOC) : null;
                if ($row) {
                    foreach (['ai_provider', 'ai_api_key', 'ai_model', 'school_name',
                              'nadics_enabled', 'nadics_name', 'nadics_persona'] as $k) {
                        if (isset($row[$k]) && $row[$k] !== '') {
                            $config[$k] = $row[$k];
                        }
                    }
                }
            }
        } catch (\Throwable $e) {
            error_log("Nadics AI config read failed: " . $e->getMessage());
        }

        if (trim($config['nadics_name']) === '') {
            $config['nadics_name'] = 'Nadics AI';
        }
        return $config;
    }
}

if (!function_exists('nadicsAIChat')) {
    /**
     * Generate an assistant reply to a user's message.
     *
     * @param string $message The user's latest message.
     * @param array  $history Prior turns: [['role'=>'user'|'assistant','text'=>..], ..]
     * @param array  $context Role-scoped facts assembled by the caller. Keys:
     *                        role_label, user_name, school_name, academic, facts (assoc).
     * @return array { success, reply, source }
     */
    function nadicsAIChat($message, array $history, array $context) {
        $config   = getNadicsConfig();
        $provider = strtolower($config['ai_provider'] ?? 'builtin');
        $apiKey   = trim($config['ai_api_key'] ?? '');
        $meta     = aiProviderMeta();

        if ($provider !== 'builtin' && $apiKey !== '' && isset($meta[$provider])) {
            $type  = $meta[$provider]['type'];
            $model = aiResolveModel($provider, $config['ai_model'] ?? '', $meta);

            if ($type === 'gemini') {
                $res = nadicsGeminiChat($message, $history, $context, array_merge($config, ['ai_model' => $model]));
            } else { // OpenAI-compatible (Groq, OpenRouter, Mistral, Cohere, OpenAI)
                $res = nadicsOpenAICompatChat($message, $history, $context, $config, $meta[$provider], $model);
            }

            if (!empty($res['success'])) {
                return $res;
            }
            // Provider failed (rate limit, network, bad key) — fall back so the
            // user still gets a useful answer instead of an error.
            $fb = nadicsBuiltinReply($message, $context, $config);
            $fb['source'] = 'builtin-fallback';
            return $fb;
        }

        return nadicsBuiltinReply($message, $context, $config);
    }
}

if (!function_exists('nadicsOpenAICompatChat')) {
    /**
     * Multi-turn chat via any OpenAI-compatible Chat Completions endpoint
     * (Groq, OpenRouter, Mistral, Cohere, OpenAI). One implementation, many
     * providers — they share the request/response shape.
     */
    function nadicsOpenAICompatChat($message, array $history, array $context, array $config, array $meta, $model) {
        $apiKey   = trim($config['ai_api_key']);
        $endpoint = $meta['endpoint'];

        $messages = [['role' => 'system', 'content' => buildNadicsSystemPrompt($context, $config)]];
        foreach ($history as $turn) {
            $role = ($turn['role'] ?? 'user') === 'assistant' ? 'assistant' : 'user';
            $text = trim((string)($turn['text'] ?? ''));
            if ($text === '') { continue; }
            $messages[] = ['role' => $role, 'content' => $text];
        }
        $messages[] = ['role' => 'user', 'content' => (string)$message];

        $payload = [
            'model'       => $model,
            'messages'    => $messages,
            'temperature' => 0.7,
            'max_tokens'  => 1500,
        ];

        $headers = [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $apiKey,
        ];
        // OpenRouter recommends identifying the app (optional but polite).
        if (strpos($endpoint, 'openrouter.ai') !== false) {
            $headers[] = 'HTTP-Referer: https://school-ms.local';
            $headers[] = 'X-Title: Nadics AI';
        }

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $endpoint);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 40);

        $result   = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr  = curl_error($ch);
        curl_close($ch);

        if ($curlErr) {
            error_log("Nadics AI ({$endpoint}) connection error: " . $curlErr);
            return ['success' => false, 'message' => 'Connection error'];
        }

        $response = json_decode($result, true);

        if ($httpCode !== 200) {
            $apiMsg = $response['error']['message'] ?? (is_string($response['error'] ?? null) ? $response['error'] : 'HTTP ' . $httpCode);
            error_log("Nadics AI provider error ({$endpoint}): " . $apiMsg);
            return ['success' => false, 'message' => $apiMsg];
        }

        $text = $response['choices'][0]['message']['content'] ?? '';
        if (is_array($text)) { // some providers return content as an array of parts
            $text = implode('', array_map(function ($p) {
                return is_array($p) ? ($p['text'] ?? '') : (string)$p;
            }, $text));
        }
        if (trim((string)$text) === '') {
            return ['success' => false, 'message' => 'Empty response from AI provider'];
        }

        return ['success' => true, 'reply' => trim($text), 'source' => 'ai'];
    }
}

if (!function_exists('buildNadicsSystemPrompt')) {
    /**
     * Build the system instruction describing who the assistant is, what the
     * user is allowed to see, and the factual context it may reference.
     */
    function buildNadicsSystemPrompt(array $context, array $config) {
        $name      = $config['nadics_name'] ?: 'Nadics AI';
        $school    = $context['school_name'] ?? ($config['school_name'] ?? 'the school');
        $roleLabel = $context['role_label'] ?? 'user';
        $userName  = $context['user_name'] ?? 'there';
        $persona   = trim($config['nadics_persona'] ?? '');

        $lines = [];
        $lines[] = "You are {$name}, the knowledgeable and friendly AI assistant inside the {$school} school management system.";
        $lines[] = "You are currently helping {$userName}, whose role is: {$roleLabel}.";
        $lines[] = "Your job is to help with: navigating the system, understanding academic schedules, grades, attendance, fees, library, online learning, communication, reports, and general school operations and questions.";
        $lines[] = "How to answer (important):";
        $lines[] = "- Be thorough and genuinely helpful. Give complete, detailed answers rather than one-liners. Aim for a few short paragraphs or a numbered list of steps when explaining how to do something.";
        $lines[] = "- When the user asks how to do or find something, give an explicit step-by-step click path (e.g. \"1. Open the left sidebar  2. Click 'Attendance'  3. Choose 'Daily Register'\"). Name the exact menus and buttons.";
        $lines[] = "- Anticipate the obvious follow-up and address it proactively, but stay on topic.";
        $lines[] = "- Use simple Markdown: **bold** for menu/button names, and numbered or bulleted lists. Keep paragraphs short and easy to scan.";
        $lines[] = "- Tailor the depth and the navigation paths to the user's role ({$roleLabel}). Do not point a student to staff-only pages.";
        $lines[] = "- Respect the user's role. Never reveal or invent data about other people. Only discuss the data provided to you below, or give general guidance.";
        $lines[] = "- If you do not have a specific figure or record (e.g. a date, an exact balance), say so plainly and tell the user exactly where in the system they can see it, with the click path. Never guess or fabricate numbers, dates, or names.";
        $lines[] = "- If a question is outside school operations, answer briefly and helpfully where reasonable, then steer back to how you can help with the school system.";
        $lines[] = "- Never ask for or repeat passwords. Do not provide instructions that bypass access controls.";

        if ($persona !== '') {
            $lines[] = "Additional instructions from the school administrator: {$persona}";
        }

        if (!empty($context['academic'])) {
            $lines[] = "Current academic context: {$context['academic']}.";
        }

        if (!empty($context['facts']) && is_array($context['facts'])) {
            $lines[] = "Facts about this user that you may reference when relevant:";
            foreach ($context['facts'] as $label => $value) {
                if ($value === '' || $value === null) { continue; }
                $lines[] = "- {$label}: {$value}";
            }
        }

        if (!empty($context['live_data']) && is_array($context['live_data'])) {
            $lines[] = "";
            $lines[] = "LIVE DATA retrieved from the system for THIS request. The user IS authorised to see this data, and it is current. Use it to answer the user's question directly and present it clearly (e.g. as a list). Do not claim you cannot access it:";
            foreach ($context['live_data'] as $label => $value) {
                if ($value === '' || $value === null) { continue; }
                $lines[] = "### {$label}";
                $lines[] = (string)$value;
            }
        }

        return implode("\n", $lines);
    }
}

if (!function_exists('nadicsGeminiChat')) {
    /**
     * Multi-turn chat via Google Gemini's free-tier generateContent API.
     */
    function nadicsGeminiChat($message, array $history, array $context, array $config) {
        $apiKey = trim($config['ai_api_key']);
        $model  = $config['ai_model'] ?: 'gemini-2.5-flash';

        $endpoint = 'https://generativelanguage.googleapis.com/v1beta/models/'
            . rawurlencode($model) . ':generateContent?key=' . urlencode($apiKey);

        // Map prior turns to Gemini's contents (roles: 'user' / 'model').
        $contents = [];
        foreach ($history as $turn) {
            $role = ($turn['role'] ?? 'user') === 'assistant' ? 'model' : 'user';
            $text = trim((string)($turn['text'] ?? ''));
            if ($text === '') { continue; }
            $contents[] = ['role' => $role, 'parts' => [['text' => $text]]];
        }
        $contents[] = ['role' => 'user', 'parts' => [['text' => (string)$message]]];

        $payload = [
            'systemInstruction' => [
                'parts' => [['text' => buildNadicsSystemPrompt($context, $config)]],
            ],
            'contents' => $contents,
            'generationConfig' => [
                'temperature'     => 0.7,
                'maxOutputTokens' => 1500,
            ],
        ];

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $endpoint);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);

        $result   = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr  = curl_error($ch);
        curl_close($ch);

        if ($curlErr) {
            error_log("Nadics AI connection error: " . $curlErr);
            return ['success' => false, 'message' => 'Connection error'];
        }

        $response = json_decode($result, true);

        if ($httpCode !== 200) {
            $apiMsg = $response['error']['message'] ?? ('HTTP ' . $httpCode);
            error_log("Nadics AI provider error: " . $apiMsg);
            return ['success' => false, 'message' => $apiMsg];
        }

        $text = $response['candidates'][0]['content']['parts'][0]['text'] ?? '';
        if (trim($text) === '') {
            return ['success' => false, 'message' => 'Empty response from AI provider'];
        }

        return [
            'success' => true,
            'reply'   => trim($text),
            'source'  => 'ai',
        ];
    }
}

if (!function_exists('nadicsBuiltinReply')) {
    /**
     * Offline, rule-based responder. Recognises common school topics and gives
     * detailed, step-by-step guidance pointing the user to the right part of the
     * system. Works without any API key so the assistant is useful for free out
     * of the box. Matching is whole-word and intent is scored so the most
     * relevant topic wins (e.g. "attendance marked" maps to attendance, not
     * grades). For richer, fully conversational answers, connect an AI provider
     * in Settings > AI & Assistant.
     */
    function nadicsBuiltinReply($message, array $context, array $config) {
        $name  = $config['nadics_name'] ?: 'Nadics AI';
        $msg   = ' ' . strtolower(trim($message)) . ' ';
        $facts = $context['facts'] ?? [];
        $role  = strtolower($context['role_label'] ?? '');

        // If live data was retrieved for this request (the user is authorised to
        // see it), present it directly even in offline mode.
        if (!empty($context['live_data']) && is_array($context['live_data'])) {
            $parts = ["Here's what I found:"];
            foreach ($context['live_data'] as $label => $value) {
                if ($value === '' || $value === null) { continue; }
                $parts[] = "\n**{$label}**\n" . (string)$value;
            }
            return ['success' => true, 'reply' => implode("\n", $parts), 'source' => 'builtin-data'];
        }
        $isStaff = (strpos($role, 'teacher') !== false || strpos($role, 'admin') !== false
            || strpos($role, 'head') !== false || strpos($role, 'principal') !== false);

        // Count how many of the given whole words/phrases appear in the message.
        $score = function (array $needles) use ($msg) {
            $n = 0;
            foreach ($needles as $w) {
                $pattern = '/\b' . preg_quote($w, '/') . '\b/';
                if (preg_match($pattern, $msg)) { $n++; }
            }
            return $n;
        };

        $note = "\n\n_Tip: connect a free AI model in **Settings → AI & Assistant** for fuller, conversational answers._";

        // --- Greeting / identity (handled before topic scoring) ---------------
        if (preg_match('/\b(hello|hi|hey|good morning|good afternoon|good evening|greetings)\b/', $msg) && str_word_count($message) <= 4) {
            $hi = "Hi! I'm **{$name}**, your school assistant. I can help you with things like:\n\n"
                . "- **Grades & report cards** — where to find your results\n"
                . "- **Attendance** — viewing or marking records\n"
                . "- **Fees & payments** — invoices and balances\n"
                . "- **Timetable & calendar** — class schedules and terms\n"
                . "- **Assignments & online learning**\n"
                . "- **Library** — borrowing and returns\n"
                . "- **Navigating the system** — finding any page\n\n"
                . "What would you like help with?";
            return ['success' => true, 'reply' => $hi, 'source' => 'builtin'];
        }
        if ($score(['who', 'you', 'are']) >= 2 || $score(['what', 'can', 'do']) >= 2 || preg_match('/\bwhat are you\b/', $msg)) {
            $reply = "I'm **{$name}**, the built-in assistant for this school management system. I help everyone — students, teachers, parents and staff — find their way around and answer questions about academics, attendance, fees, the library and day-to-day school operations.\n\n"
                . "Ask me things like *“How do I check my grades?”*, *“Where do I pay fees?”* or *“How do I mark attendance?”* and I'll point you to the exact place.";
            return ['success' => true, 'reply' => $reply . $note, 'source' => 'builtin'];
        }

        // --- Score each topic; the highest score wins -------------------------
        $topics = [
            'attendance' => $score(['attendance', 'attend', 'present', 'absent', 'absence', 'register', 'marked', 'mark', 'roll']),
            'grades'     => $score(['grade', 'grades', 'result', 'results', 'score', 'scores', 'report', 'card', 'transcript', 'exam', 'gpa']),
            'fees'       => $score(['fee', 'fees', 'pay', 'payment', 'invoice', 'balance', 'tuition', 'bill', 'owe', 'outstanding']),
            'timetable'  => $score(['timetable', 'schedule', 'period', 'periods', 'lesson', 'calendar', 'term', 'time']),
            'assignment' => $score(['assignment', 'assignments', 'homework', 'submission', 'submit', 'quiz', 'task']),
            'library'    => $score(['library', 'book', 'books', 'borrow', 'borrowed', 'loan', 'return']),
            'account'    => $score(['password', 'login', 'log', 'sign', 'reset', 'profile', 'account', 'email', 'picture', 'phone']),
            'comms'      => $score(['message', 'messages', 'announcement', 'notification', 'notifications', 'chat', 'inbox', 'feedback']),
            'reports'    => $score(['report', 'reports', 'analytics', 'statistics', 'export', 'print']),
        ];
        arsort($topics);
        $top = array_key_first($topics);
        $best = $topics[$top];

        if ($best < 1) {
            $reply = "I'm **{$name}**. I can help with grades, attendance, timetables, fees, assignments, the library and finding your way around the system.\n\n"
                . "Could you tell me a little more about what you're trying to do? For example:\n"
                . "- *“Where do I see my attendance?”*\n"
                . "- *“How do I check my child's results?”*\n"
                . "- *“How do I pay school fees?”*\n\n"
                . "You can also open the full **Help Center** from the footer at the bottom of any page.";
            return ['success' => true, 'reply' => $reply . $note, 'source' => 'builtin'];
        }

        switch ($top) {
            case 'attendance':
                if ($isStaff) {
                    $reply = "**Marking & viewing attendance**\n\n"
                        . "1. Open the left **sidebar** and click **Attendance**.\n"
                        . "2. Choose **Daily Register** (or **Mark Attendance**) and select the class and date.\n"
                        . "3. Mark each student Present / Absent / Late, then **Save**.\n\n"
                        . "To review history, use **Attendance → Reports**, where you can filter by class, student and date range. ";
                    $reply .= "I can't see the exact date attendance was last marked from here — that's shown in the register list and the attendance reports.";
                } else {
                    $reply = "**Viewing attendance**\n\n"
                        . "1. Open the left **sidebar** and click **Attendance**.\n"
                        . "2. You'll see your attendance record, which you can filter by month or term.\n\n";
                    if (isset($facts['Attendance this month'])) {
                        $reply .= "Your records currently show **{$facts['Attendance this month']}** this month. ";
                    }
                    $reply .= "I can't see the exact date it was last taken from here — the attendance page lists each day, so the most recent entry there is the latest marking.";
                }
                break;

            case 'grades':
                $reply = "**Finding grades & report cards**\n\n"
                    . "1. Open the **sidebar** and go to **Academics**.\n"
                    . "2. Click **Academic Records** (or **Reports → Report Cards**) to see results by term.\n\n"
                    . ($isStaff
                        ? "As staff, you can also **enter and publish** results from **Academics → Academic Records**; grades only become visible to students/parents once published."
                        : "Results appear here once teachers have entered and **published** them. If a subject is missing, it likely hasn't been published yet — check again later or ask the class teacher.");
                break;

            case 'fees':
                $reply = "**Fees & payments**\n\n"
                    . "1. Open the **sidebar** and go to **Finance**.\n"
                    . "2. Click **Invoices** to see what's billed, and **Payments** for what's been paid.\n"
                    . "3. The outstanding **balance** is shown on each invoice.\n\n"
                    . ($isStaff
                        ? "Staff can record payments and generate invoices from the same **Finance** section."
                        : "For payment methods or to confirm a payment, contact the school office. I can't display your exact balance from here, but it's on your invoice in **Finance → Invoices**.");
                break;

            case 'timetable':
                $reply = "**Timetable & academic calendar**\n\n"
                    . "1. Open the **sidebar** and go to **Academics**.\n"
                    . "2. Look for **Timetable** / **Class Schedule** for daily periods, and the **Academic Calendar** for term dates.\n\n"
                    . "The current academic year and term are also shown at the very top of every page"
                    . (!empty($context['academic']) ? " (right now: **{$context['academic']}**)." : ".");
                break;

            case 'assignment':
                $reply = "**Assignments & online learning**\n\n"
                    . ($isStaff
                        ? "1. Go to **Academics → Assignments** to create and manage assignments.\n2. Use **Online Learning → Submissions** to review what students have turned in and grade it.\n\n"
                        : "1. Go to **Academics → Assignments** (or **Online Learning**) in the sidebar.\n2. Open an assignment to see instructions and the due date, then submit your work there.\n\n");
                if (!$isStaff && isset($facts['Pending assignments'])) {
                    $reply .= "You currently have **{$facts['Pending assignments']}** pending assignment(s).";
                }
                break;

            case 'library':
                $reply = "**Library**\n\n"
                    . "1. Open the **sidebar** and click **Library**.\n"
                    . "2. Browse or search the catalogue under **Books**.\n"
                    . "3. Your current loans and due dates are under **My Loans / Borrowed**.\n\n";
                if (isset($facts['Borrowed books'])) {
                    $reply .= "You currently have **{$facts['Borrowed books']}** book(s) on loan.";
                } else {
                    $reply .= "To borrow or return, see the school librarian or the issue desk.";
                }
                break;

            case 'account':
                $reply = "**Your account & profile**\n\n"
                    . "- **Update your details / photo:** click your name in the **top-right** corner → **My Profile**.\n"
                    . "- **Change password:** **My Profile → Security/Password**.\n"
                    . "- **Forgot password / locked out:** use the **“Forgot password”** link on the login page, or ask your administrator to reset it.\n\n"
                    . "For your security, I'll never ask for or show your password.";
                break;

            case 'comms':
                $reply = "**Messages, announcements & notifications**\n\n"
                    . "- **Notifications:** the **bell icon** at the top-right shows the latest alerts.\n"
                    . "- **Messages/Announcements:** open **Communication** in the sidebar.\n"
                    . "- **Feedback/Support:** links are in the **footer** (Send Feedback, Technical Support).\n\n"
                    . ($isStaff ? "Staff can compose announcements, SMS and emails from **Communication**, with Draft AI to help write them." : "");
                break;

            case 'reports':
                $reply = "**Reports, analytics & exports**\n\n"
                    . "1. Open the **sidebar** and look under **Reports** (and **Academics → Reports**).\n"
                    . "2. Choose the report you need, set any filters (class, term, date range), then **Generate**.\n"
                    . "3. Most reports can be **printed** or **exported** using the buttons at the top of the report.\n\n"
                    . ($isStaff ? "Admin dashboards also show summary statistics at a glance." : "Some reports are limited to staff — tell me what you're looking for and I'll point you to the right page.");
                break;

            default:
                $reply = "Most features live in the left **sidebar**, grouped by area (Academics, Attendance, Finance, Library, Communication). Tell me what you're trying to do and I'll walk you through it.";
        }

        return ['success' => true, 'reply' => trim($reply) . $note, 'source' => 'builtin'];
    }
}
