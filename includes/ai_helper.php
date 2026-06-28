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
            'ai_model'    => 'gemini-1.5-flash',
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
        $provider = $config['ai_provider'] ?? 'builtin';
        $apiKey   = trim($config['ai_api_key'] ?? '');

        if ($provider === 'gemini' && $apiKey !== '') {
            $res = generateGeminiDraft($type, $params, $config);
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

if (!function_exists('generateGeminiDraft')) {
    /**
     * Generate a draft using Google Gemini's free-tier generateContent API.
     */
    function generateGeminiDraft($type, $params, $config) {
        $apiKey = trim($config['ai_api_key']);
        $model  = $config['ai_model'] ?: 'gemini-1.5-flash';

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
