<?php
/**
 * SMS Helper Functions
 * Handles SMS sending through various gateways
 */

/**
 * Normalize a phone number to international format (digits only, no '+').
 *
 * Numbers are stored in many shapes across the system — "0244123456",
 * "+233 24 412 3456", "233244123456", "00233244123456". Most gateways
 * (especially the Ghana providers) only accept full international format, so
 * every number is funnelled through here before being handed to a gateway.
 *
 * @param string $phone               Raw phone number as stored/entered.
 * @param string $defaultCountryCode  Country dialling code to assume for local
 *                                     numbers (no leading 0/+). Defaults to 233.
 * @return string Normalized number (e.g. "233244123456") or '' if unusable.
 */
function normalizePhoneNumber($phone, $defaultCountryCode = '233') {
    if ($phone === null) {
        return '';
    }
    $raw = trim((string)$phone);
    if ($raw === '') {
        return '';
    }

    $hasPlus = (strpos($raw, '+') === 0);
    $digits = preg_replace('/\D+/', '', $raw);
    if ($digits === '') {
        return '';
    }

    $cc = preg_replace('/\D+/', '', (string)$defaultCountryCode);
    if ($cc === '') {
        $cc = '233';
    }

    // Already international (had a leading '+').
    if ($hasPlus) {
        return $digits;
    }
    // "00" international access prefix -> strip it.
    if (strpos($digits, '00') === 0) {
        return substr($digits, 2);
    }
    // National trunk "0" -> swap for the country code.
    if (strpos($digits, '0') === 0) {
        return $cc . substr($digits, 1);
    }
    // Already begins with the country code.
    if (strpos($digits, $cc) === 0) {
        return $digits;
    }
    // Bare subscriber number -> prepend the country code.
    return $cc . $digits;
}

/**
 * Send SMS through configured gateway
 *
 * @param string|array $recipients Phone number(s) - can be string or array
 * @param string $message Message content
 * @param string $sender_id Optional sender ID (uses default from settings if not provided)
 * @return array Response with success status and message
 */
function sendSMS($recipients, $message, $sender_id = null) {
    require_once __DIR__ . '/../config/database.php';
    $database = new Database();
    $db = $database->getConnection();

    // Get SMS settings
    $settings_query = "SELECT * FROM school_settings LIMIT 1";
    $settings_stmt = $db->query($settings_query);
    $settings = $settings_stmt->fetch(PDO::FETCH_ASSOC);

    if (!$settings || $settings['sms_gateway'] === 'disabled') {
        return [
            'success' => false,
            'message' => 'SMS gateway is disabled'
        ];
    }

    // Guard against empty content before spending a gateway request.
    if (trim((string)$message) === '') {
        return [
            'success' => false,
            'message' => 'Cannot send an empty SMS message'
        ];
    }

    // Use provided sender_id or default from settings
    $sender = $sender_id ?? $settings['sms_sender_id'] ?? 'SCHOOL';

    // Convert single recipient to array
    if (!is_array($recipients)) {
        $recipients = [$recipients];
    }

    // Normalize every number to international format and drop any that are
    // unusable, so gateways never reject the whole batch over a stray "0244..".
    $default_cc = $settings['sms_country_code'] ?? '233';
    $normalized = [];
    foreach ($recipients as $r) {
        $n = normalizePhoneNumber($r, $default_cc);
        if ($n !== '' && !in_array($n, $normalized, true)) {
            $normalized[] = $n;
        }
    }
    if (empty($normalized)) {
        return [
            'success' => false,
            'message' => 'No valid recipient phone number(s) to send to'
        ];
    }
    $recipients = $normalized;

    // Route to appropriate gateway
    switch ($settings['sms_gateway']) {
        case 'mnotify':
        case 'notifysms':
            return sendMNotifySMS($recipients, $message, $sender, $settings);
            
        case 'hubtel':
            return sendHubtelSMS($recipients, $message, $sender, $settings);
            
        case 'twilio':
            return sendTwilioSMS($recipients, $message, $sender, $settings);
            
        case 'termii':
            return sendTermiiSMS($recipients, $message, $sender, $settings);
            
        case 'wigal':
            return sendWigalSMS($recipients, $message, $sender, $settings);
            
        case 'nalopay':
            return sendNalopaySMS($recipients, $message, $sender, $settings);
            
        case 'onlinegh':
            return sendOnlineGHSMS($recipients, $message, $sender, $settings);
            
        case 'nexmo':
            return sendNexmoSMS($recipients, $message, $sender, $settings);
            
        default:
            return [
                'success' => false,
                'message' => 'Unsupported SMS gateway: ' . $settings['sms_gateway']
            ];
    }
}

/**
 * Send SMS via mNotify
 */
function sendMNotifySMS($recipients, $message, $sender, $settings) {
    $endPoint = 'https://api.mnotify.com/api/sms/quick';
    $apiKey = $settings['sms_api_key'];
    
    if (empty($apiKey)) {
        return [
            'success' => false,
            'message' => 'mNotify API key not configured'
        ];
    }
    
    $url = $endPoint . '?key=' . $apiKey;
    
    // Format recipients - mNotify accepts comma-separated numbers
    $recipient_string = is_array($recipients) ? implode(',', $recipients) : $recipients;
    
    $data = [
        'recipient' => [$recipient_string],
        'sender' => $sender,
        'message' => $message,
        'is_schedule' => 'false',
        'schedule_date' => ''
    ];
    
    $ch = curl_init();
    $headers = ["Content-Type: application/json"];
    
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "POST");
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
    
    $result = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curl_error = curl_error($ch);
    curl_close($ch);
    
    if ($curl_error) {
        return [
            'success' => false,
            'message' => 'cURL Error: ' . $curl_error
        ];
    }
    
    $response = json_decode($result, true);
    
    if ($http_code == 200 && isset($response['status']) && $response['status'] === 'success') {
        return [
            'success' => true,
            'message' => 'SMS sent successfully via mNotify',
            'response' => $response
        ];
    } else {
        return [
            'success' => false,
            'message' => $response['message'] ?? 'Failed to send SMS via mNotify',
            'response' => $response
        ];
    }
}

/**
 * Send SMS via Hubtel
 */
function sendHubtelSMS($recipients, $message, $sender, $settings) {
    $endPoint = 'https://devp-sms.hubtel.com/v1/messages/send';
    $clientId = $settings['sms_api_key'];
    $clientSecret = $settings['sms_api_secret'];
    
    if (empty($clientId) || empty($clientSecret)) {
        return [
            'success' => false,
            'message' => 'Hubtel credentials not configured'
        ];
    }
    
    $success_count = 0;
    $failed_count = 0;
    $errors = [];
    
    foreach ($recipients as $recipient) {
        $data = [
            'From' => $sender,
            'To' => $recipient,
            'Content' => $message
        ];
        
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $endPoint);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data));
        curl_setopt($ch, CURLOPT_USERPWD, $clientId . ':' . $clientSecret);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        
        $result = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        if ($http_code == 201) {
            $success_count++;
        } else {
            $failed_count++;
            $errors[] = "Failed to send to $recipient";
        }
    }
    
    return [
        'success' => $success_count > 0,
        'message' => "Sent to $success_count recipient(s), $failed_count failed",
        'details' => [
            'success' => $success_count,
            'failed' => $failed_count,
            'errors' => $errors
        ]
    ];
}

/**
 * Send SMS via Twilio
 */
function sendTwilioSMS($recipients, $message, $sender, $settings) {
    // Twilio Programmable Messaging REST API.
    // Docs: https://www.twilio.com/docs/messaging/api/message-resource
    $accountSid = trim($settings['sms_api_key'] ?? '');
    $authToken  = trim($settings['sms_api_secret'] ?? '');

    if ($accountSid === '' || $authToken === '') {
        return [
            'success' => false,
            'message' => 'Twilio credentials not configured — put the Account SID in API Key and the Auth Token in API Secret.'
        ];
    }

    $endPoint = "https://api.twilio.com/2010-04-01/Accounts/" . rawurlencode($accountSid) . "/Messages.json";

    // The sender can be a Messaging Service SID (starts with "MG", 34 chars), an
    // alphanumeric Sender ID, or a Twilio phone number. Phone numbers must be in
    // E.164 format (leading +); alphanumeric IDs are sent verbatim.
    $useMessagingService = (stripos($sender, 'MG') === 0 && strlen($sender) === 34);
    $fromValue = $sender;
    if (!$useMessagingService && preg_match('/^\d+$/', $sender)) {
        $fromValue = '+' . $sender; // bare digits -> E.164 Twilio number
    }

    $success_count = 0;
    $failed_count = 0;
    $last_error = null;
    $last_response = null;

    foreach ($recipients as $recipient) {
        // Twilio requires E.164. normalizePhoneNumber() returns digits only, so
        // restore the leading '+'.
        $to = '+' . ltrim((string)$recipient, '+');

        $data = [
            'To'   => $to,
            'Body' => $message,
        ];
        if ($useMessagingService) {
            $data['MessagingServiceSid'] = $sender;
        } else {
            $data['From'] = $fromValue;
        }

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $endPoint);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data));
        curl_setopt($ch, CURLOPT_USERPWD, $accountSid . ':' . $authToken);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);

        $result = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curl_error = curl_error($ch);
        curl_close($ch);

        if ($curl_error) {
            $failed_count++;
            $last_error = 'cURL Error: ' . $curl_error;
            continue;
        }

        $resp = json_decode($result, true);

        // Success: HTTP 2xx and Twilio did not report an error_code/code.
        if ($http_code >= 200 && $http_code < 300 && empty($resp['error_code']) && empty($resp['code'])) {
            $success_count++;
            $last_response = $resp ?: $result;
        } else {
            $failed_count++;
            // Twilio error JSON: {"code":21211,"message":"...","more_info":"..."}
            $emsg  = $resp['message'] ?? ($resp['error_message'] ?? trim((string)$result));
            $ecode = $resp['code'] ?? ($resp['error_code'] ?? $http_code);
            $last_error = $emsg . ($ecode ? " (code $ecode)" : '');
            $last_response = $resp ?: $result;
        }
    }

    if ($success_count > 0) {
        return [
            'success'  => true,
            'message'  => "Sent to $success_count recipient(s)" . ($failed_count ? ", $failed_count failed" : '') . ' via Twilio',
            'response' => $last_response,
        ];
    }

    return [
        'success'  => false,
        'message'  => 'Twilio rejected the message — ' . ($last_error ?: 'no response from gateway'),
        'response' => $last_response,
    ];
}

/**
 * Send SMS via Termii
 */
function sendTermiiSMS($recipients, $message, $sender, $settings) {
    $endPoint = 'https://api.ng.termii.com/api/sms/send';
    $apiKey = $settings['sms_api_key'];
    
    if (empty($apiKey)) {
        return [
            'success' => false,
            'message' => 'Termii API key not configured'
        ];
    }
    
    $data = [
        'to' => implode(',', $recipients),
        'from' => $sender,
        'sms' => $message,
        'type' => 'plain',
        'channel' => 'generic',
        'api_key' => $apiKey
    ];
    
    $ch = curl_init();
    $headers = ["Content-Type: application/json"];
    
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_URL, $endPoint);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
    
    $result = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    $response = json_decode($result, true);
    
    return [
        'success' => $http_code == 200,
        'message' => $response['message'] ?? 'SMS sent via Termii',
        'response' => $response
    ];
}

/**
 * Send SMS via Wigal
 */
function sendWigalSMS($recipients, $message, $sender, $settings) {
    // Wigal Frog SMS API v3 (POST JSON).
    // Docs: https://frogdocs.wigal.com.gh/send_general.html
    $endPoint = 'https://frogapi.wigal.com.gh/api/v3/sms/send';

    // Wigal authenticates with two header values: API-KEY and USERNAME.
    // Map: "API Key" field -> API-KEY, "API Secret" field -> USERNAME.
    $apiKey   = trim($settings['sms_api_key'] ?? '');
    $username = trim($settings['sms_api_secret'] ?? '');

    if ($apiKey === '' || $username === '') {
        return [
            'success' => false,
            'message' => 'Wigal credentials not configured — set the API Key, and your Wigal Username in the API Secret field.'
        ];
    }

    // Each recipient is an object with the number and a unique message id.
    $destinations = [];
    foreach (array_values($recipients) as $i => $r) {
        $destinations[] = [
            'destination' => $r,
            'msgid'       => 'MSG' . date('YmdHis') . $i . mt_rand(100, 999),
        ];
    }

    $payload = [
        'senderid'     => $sender,
        'destinations' => $destinations,
        'message'      => $message,
        'smstype'      => 'text',
    ];

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $endPoint);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json',
        'API-KEY: ' . $apiKey,
        'USERNAME: ' . $username,
    ]);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);

    $result = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curl_error = curl_error($ch);
    curl_close($ch);

    if ($curl_error) {
        return ['success' => false, 'message' => 'cURL Error: ' . $curl_error];
    }

    $decoded = json_decode($result, true);
    $status = (is_array($decoded) && isset($decoded['status'])) ? strtoupper((string)$decoded['status']) : '';

    // Documented success is HTTP 200 with status "ACCEPTD".
    if ($status === 'ACCEPTD') {
        return [
            'success'  => true,
            'message'  => 'SMS sent successfully via Wigal',
            'response' => $decoded ?: $result,
        ];
    }

    // Map documented failure statuses to readable reasons.
    $reasons = [
        'INVALID_REQUEST'     => 'Invalid request — a required parameter is missing or malformed',
        'SENDER_ID_NOT_FOUND' => 'Sender ID not found — it must be an approved Wigal sender ID',
        'ERROR'               => 'Server error at Wigal',
    ];
    $api_msg = is_array($decoded) ? ($decoded['message'] ?? null) : null;
    $reason  = $reasons[$status]
        ?? ($api_msg ?: ('HTTP ' . $http_code . ': ' . trim((string)$result)));
    if ($http_code == 401) { $reason = 'Unauthorized — check your API Key and Username'; }
    if ($http_code == 403) { $reason = 'Forbidden — your account is not permitted to send'; }

    return [
        'success'  => false,
        'message'  => 'Wigal rejected the message — ' . $reason . ($status ? " ($status)" : ''),
        'response' => $decoded ?: $result,
    ];
}

/**
 * Send SMS via Nalopay
 */
function sendNalopaySMS($recipients, $message, $sender, $settings) {
    // Nalo Solutions SMS API (POST JSON).
    // Docs: https://documenter.getpostman.com/view/7705958/Uyr7Hydn
    $endPoint = 'https://sms.nalosolutions.com/smsbackend/Resl_Nalo/send-message/';

    $apiKey    = trim($settings['sms_api_key'] ?? '');
    $apiSecret = trim($settings['sms_api_secret'] ?? '');

    // Nalo accepts a comma-separated recipient list in a single call.
    $msisdn = implode(',', $recipients);

    // Two documented authentication modes share the same endpoint:
    //  - Username + Password : put the username in "API Key" and the password
    //                          in "API Secret".
    //  - Portal-generated Key: put the key in "API Key" and leave "API Secret"
    //                          blank (when a key is sent, username/password are
    //                          ignored by Nalo).
    if ($apiSecret !== '') {
        $payload = [
            'username'  => $apiKey,
            'password'  => $apiSecret,
            'msisdn'    => $msisdn,
            'message'   => $message,
            'sender_id' => $sender,
        ];
    } elseif ($apiKey !== '') {
        $payload = [
            'key'       => $apiKey,
            'msisdn'    => $msisdn,
            'message'   => $message,
            'sender_id' => $sender,
        ];
    } else {
        return [
            'success' => false,
            'message' => 'Nalo credentials not configured — set an API key, or a username + password.'
        ];
    }

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $endPoint);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);

    $result = curl_exec($ch);
    $curl_error = curl_error($ch);
    curl_close($ch);

    if ($curl_error) {
        return ['success' => false, 'message' => 'cURL Error: ' . $curl_error];
    }

    // Success replies as JSON {"status":"1701",...} or pipe text "1701|<msisdn>|<job_id>".
    // Errors may arrive as {"status":"17xx"} or {"code":17xx,"message":"..."}.
    $decoded = json_decode($result, true);
    $status_code = null;
    if (is_array($decoded) && isset($decoded['status'])) {
        $status_code = (string)$decoded['status'];
    } elseif (is_array($decoded) && isset($decoded['code'])) {
        $status_code = (string)$decoded['code'];
    } elseif (preg_match('/^\s*(\d{3,4})/', (string)$result, $m)) {
        $status_code = $m[1];
    }

    if ($status_code === '1701') {
        return [
            'success'  => true,
            'message'  => 'SMS sent successfully via Nalo',
            'response' => $decoded ?: $result,
        ];
    }

    // Documented Nalo error codes -> human readable reasons.
    $nalo_errors = [
        '1702' => 'Invalid URL / a required parameter is missing or blank',
        '1703' => 'Invalid username or password',
        '1704' => 'Invalid "type" value',
        '1705' => 'Invalid message',
        '1706' => 'Invalid destination (recipient number)',
        '1707' => 'Invalid sender ID — it must be registered/approved with Nalo (max 11 characters)',
        '1708' => 'Invalid "dlr" value',
        '1709' => 'User validation failed — wrong key or username/password',
        '1710' => 'Internal error at Nalo',
        '1713' => 'Invalid auth key — the API key is not recognised by Nalo (check the key, or switch to username + password)',
        '1025' => 'Insufficient credit (user account)',
        '1026' => 'Insufficient credit (reseller account)',
    ];
    $reason = $nalo_errors[$status_code] ?? ('Nalo returned: ' . trim((string)$result));

    return [
        'success'  => false,
        'message'  => 'Nalo rejected the message — ' . $reason . ($status_code ? " (code $status_code)" : ''),
        'response' => $decoded ?: $result,
    ];
}

/**
 * Send SMS via Online GH
 */
function sendOnlineGHSMS($recipients, $message, $sender, $settings) {
    // Placeholder - Update with actual Online GH API endpoint when available
    $apiKey = $settings['sms_api_key'];
    
    if (empty($apiKey)) {
        return [
            'success' => false,
            'message' => 'Online GH API key not configured'
        ];
    }
    
    return [
        'success' => false,
        'message' => 'Online GH integration pending - contact provider for API details'
    ];
}

/**
 * Send SMS via Nexmo/Vonage
 */
function sendNexmoSMS($recipients, $message, $sender, $settings) {
    $endPoint = 'https://rest.nexmo.com/sms/json';
    $apiKey = $settings['sms_api_key'];
    $apiSecret = $settings['sms_api_secret'];
    
    if (empty($apiKey) || empty($apiSecret)) {
        return [
            'success' => false,
            'message' => 'Nexmo credentials not configured'
        ];
    }
    
    $success_count = 0;
    $failed_count = 0;
    
    foreach ($recipients as $recipient) {
        $data = [
            'api_key' => $apiKey,
            'api_secret' => $apiSecret,
            'from' => $sender,
            'to' => $recipient,
            'text' => $message
        ];
        
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $endPoint);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data));
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        
        $result = curl_exec($ch);
        curl_close($ch);
        
        $response = json_decode($result, true);
        
        if (isset($response['messages'][0]['status']) && $response['messages'][0]['status'] == '0') {
            $success_count++;
        } else {
            $failed_count++;
        }
    }
    
    return [
        'success' => $success_count > 0,
        'message' => "Sent to $success_count recipient(s), $failed_count failed"
    ];
}

/**
 * Log SMS activity
 */
function logSMS($recipients, $message, $status, $gateway, $response = null) {
    require_once __DIR__ . '/../config/database.php';
    $database = new Database();
    $db = $database->getConnection();
    
    try {
        // Create SMS log table if it doesn't exist
        $create_table = "CREATE TABLE IF NOT EXISTS sms_logs (
            id INT AUTO_INCREMENT PRIMARY KEY,
            recipients TEXT NOT NULL,
            message TEXT NOT NULL,
            gateway VARCHAR(50) NOT NULL,
            status VARCHAR(20) NOT NULL,
            response TEXT,
            sent_by INT,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        )";
        $db->exec($create_table);
        
        $recipients_str = is_array($recipients) ? implode(', ', $recipients) : $recipients;
        $response_str = $response ? json_encode($response) : null;
        $sent_by = $_SESSION['user_id'] ?? null;
        
        $insert = "INSERT INTO sms_logs (recipients, message, gateway, status, response, sent_by) 
                   VALUES (:recipients, :message, :gateway, :status, :response, :sent_by)";
        $stmt = $db->prepare($insert);
        $stmt->execute([
            ':recipients' => $recipients_str,
            ':message' => $message,
            ':gateway' => $gateway,
            ':status' => $status,
            ':response' => $response_str,
            ':sent_by' => $sent_by
        ]);
        
        return true;
    } catch (PDOException $e) {
        error_log("SMS Log Error: " . $e->getMessage());
        return false;
    }
}
?>
