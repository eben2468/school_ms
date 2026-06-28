<?php
/**
 * Email Helper Functions
 * Handles SMTP and fallback email transmission and logging
 */

/**
 * Send Email via configured SMTP settings, falling back to local mail() on failure.
 * 
 * @param string|array $recipients Email address(es) - string or array
 * @param string $subject Subject of the email
 * @param string $message HTML content of the email
 * @return array Status description
 */
function sendEmail($recipients, $subject, $message) {
    require_once __DIR__ . '/../config/database.php';
    $database = new Database();
    $db = $database->getConnection();
    
    // Get settings
    $settings_query = "SELECT * FROM school_settings LIMIT 1";
    $settings_stmt = $db->query($settings_query);
    $settings = $settings_stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$settings || $settings['email_notifications'] === 'disabled') {
        return [
            'success' => false,
            'message' => 'Email notifications are disabled in school settings'
        ];
    }
    
    // Normalize recipients to array
    if (!is_array($recipients)) {
        // If comma-separated string, split it
        if (strpos($recipients, ',') !== false) {
            $recipients = array_map('trim', explode(',', $recipients));
        } else {
            $recipients = [trim($recipients)];
        }
    }
    
    $success_count = 0;
    $failed_count = 0;
    $errors = [];
    
    foreach ($recipients as $recipient) {
        $recipient = trim($recipient);
        if (empty($recipient) || !filter_var($recipient, FILTER_VALIDATE_EMAIL)) {
            $failed_count++;
            $errors[] = "Invalid email address format: '$recipient'";
            continue;
        }
        
        $sent = false;
        $err_msg = '';
        
        // 1. Try SMTP if configured
        if (!empty($settings['smtp_host'])) {
            $smtp_result = sendEmailSMTP($recipient, $subject, $message, $settings);
            if ($smtp_result['success']) {
                $success_count++;
                $sent = true;
                logEmail($recipient, $subject, $message, 'success', null, $db);
            } else {
                $err_msg = $smtp_result['message'];
                error_log("SMTP send to $recipient failed: $err_msg. Trying php mail() fallback.");
            }
        } else {
            $err_msg = 'SMTP host not configured';
        }
        
        // 2. Try PHP mail() fallback if SMTP was not used or failed
        if (!$sent) {
            $from_email = $settings['school_email'] ?? 'noreply@' . ($_SERVER['SERVER_NAME'] ?? 'localhost');
            $school_name = $settings['school_name'] ?? 'School Management System';
            
            $headers = [
                "From: " . "=?utf-8?B?" . base64_encode($school_name) . "?=" . " <$from_email>",
                "Reply-To: $from_email",
                "MIME-Version: 1.0",
                "Content-Type: text/html; charset=UTF-8",
                "X-Mailer: PHP/" . phpversion()
            ];
            
            // Suppress errors during mail() call
            $mail_sent = @mail($recipient, $subject, $message, implode("\r\n", $headers));
            
            if ($mail_sent) {
                $success_count++;
                $sent = true;
                logEmail($recipient, $subject, $message, 'success', 'Sent via mail() fallback (SMTP failed or unconfigured: ' . $err_msg . ')', $db);
            } else {
                $failed_count++;
                $errors[] = "Failed to send to $recipient (SMTP: $err_msg, Fallback: mail() failed)";
                logEmail($recipient, $subject, $message, 'failed', "SMTP: $err_msg. Fallback: mail() failed.", $db);
            }
        }
    }
    
    return [
        'success' => $success_count > 0,
        'message' => "Email broadcast: $success_count succeeded, $failed_count failed.",
        'details' => [
            'success' => $success_count,
            'failed' => $failed_count,
            'errors' => $errors
        ]
    ];
}

/**
 * Connects directly to the SMTP host and executes transmission.
 */
function sendEmailSMTP($recipient, $subject, $message, $settings, &$log_output = null) {
    $host = trim($settings['smtp_host'] ?? '');
    $port = (int)($settings['smtp_port'] ?? 587) ?: 587;
    $username = $settings['smtp_username'] ?? '';
    $password = $settings['smtp_password'] ?? '';
    $encryption = $settings['smtp_encryption'] ?? 'tls';
    $school_name = $settings['school_name'] ?? 'School Management System';

    $from_email = !empty($username) ? $username : 'noreply@' . ($_SERVER['SERVER_NAME'] ?? 'localhost');

    $debug = [];
    $log = function($msg) use (&$debug, &$log_output) {
        $debug[] = htmlspecialchars($msg);
        if ($log_output !== null) {
            $log_output .= $msg . "\n";
        }
    };

    // Pre-flight: a missing host is the #1 cause of "connection refused (10061)".
    // Fail fast with an actionable message instead of dialing an empty address.
    if ($host === '') {
        $log("SMTP host is not configured.");
        return [
            'success' => false,
            'message' => 'SMTP host is not configured. Go to Settings → Email and enter your SMTP server (e.g. smtp.gmail.com), then click Save Changes before testing.',
            'debug' => $debug
        ];
    }

    $socket_host = $host;
    if ($encryption === 'ssl') {
        $socket_host = 'ssl://' . $host;
    }
    
    $log("Connecting to $socket_host:$port...");
    $socket = @fsockopen($socket_host, $port, $errno, $errstr, 10);
    
    if (!$socket) {
        return [
            'success' => false,
            'message' => "Socket connection failed: $errstr ($errno)",
            'debug' => $debug
        ];
    }
    
    // Helper to read server responses
    $read = function($socket) use ($log) {
        $response = '';
        while ($line = fgets($socket, 515)) {
            $response .= $line;
            if (substr($line, 3, 1) === ' ') {
                break;
            }
        }
        $log("S: " . trim($response));
        return $response;
    };
    
    // Helper to send SMTP commands
    $send = function($socket, $cmd) use ($read, $log) {
        $log("C: " . $cmd);
        fputs($socket, $cmd . "\r\n");
        return $read($socket);
    };
    
    $response = $read($socket); // Read initial greeting
    if (strpos($response, '220') !== 0) {
        fclose($socket);
        return ['success' => false, 'message' => 'Invalid SMTP server greeting', 'debug' => $debug];
    }
    
    // EHLO
    $response = $send($socket, "EHLO " . ($_SERVER['SERVER_NAME'] ?? 'localhost'));
    if (strpos($response, '250') !== 0) {
        fclose($socket);
        return ['success' => false, 'message' => 'EHLO command rejected', 'debug' => $debug];
    }
    
    // STARTTLS if TLS encryption and supported
    if ($encryption === 'tls' && strpos($response, 'STARTTLS') !== false) {
        $response = $send($socket, "STARTTLS");
        if (strpos($response, '220') === 0) {
            $log("Enabling TLS crypto on socket...");
            if (!@stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                fclose($socket);
                return ['success' => false, 'message' => 'TLS crypt negotiation failed', 'debug' => $debug];
            }
            $log("TLS negotiation successful. Sending EHLO again...");
            $response = $send($socket, "EHLO " . ($_SERVER['SERVER_NAME'] ?? 'localhost'));
            if (strpos($response, '250') !== 0) {
                fclose($socket);
                return ['success' => false, 'message' => 'EHLO command rejected after TLS', 'debug' => $debug];
            }
        } else {
            fclose($socket);
            return ['success' => false, 'message' => 'STARTTLS negotiation failed: ' . trim($response), 'debug' => $debug];
        }
    }
    
    // Authenticate
    if (!empty($username) && !empty($password)) {
        $response = $send($socket, "AUTH LOGIN");
        if (strpos($response, '334') === 0) {
            $response = $send($socket, base64_encode($username));
            if (strpos($response, '334') === 0) {
                $response = $send($socket, base64_encode($password));
                if (strpos($response, '235') !== 0) {
                    fclose($socket);
                    return ['success' => false, 'message' => 'Authentication failed (incorrect credentials): ' . trim($response), 'debug' => $debug];
                }
            } else {
                fclose($socket);
                return ['success' => false, 'message' => 'Username rejected: ' . trim($response), 'debug' => $debug];
            }
        } else {
            fclose($socket);
            return ['success' => false, 'message' => 'AUTH LOGIN command not supported/rejected: ' . trim($response), 'debug' => $debug];
        }
    }
    
    // MAIL FROM
    $response = $send($socket, "MAIL FROM:<$from_email>");
    if (strpos($response, '250') !== 0) {
        fclose($socket);
        return ['success' => false, 'message' => 'MAIL FROM command rejected: ' . trim($response), 'debug' => $debug];
    }
    
    // RCPT TO
    $response = $send($socket, "RCPT TO:<$recipient>");
    if (strpos($response, '250') !== 0 && strpos($response, '251') !== 0) {
        fclose($socket);
        return ['success' => false, 'message' => 'RCPT TO command rejected: ' . trim($response), 'debug' => $debug];
    }
    
    // DATA
    $response = $send($socket, "DATA");
    if (strpos($response, '354') !== 0) {
        fclose($socket);
        return ['success' => false, 'message' => 'DATA command rejected: ' . trim($response), 'debug' => $debug];
    }
    
    // Format headers and message data
    $subject_base64 = "=?utf-8?B?" . base64_encode($subject) . "?=";
    $from_base64 = "=?utf-8?B?" . base64_encode($school_name) . "?=";
    
    $headers = [
        "From: $from_base64 <$from_email>",
        "To: <$recipient>",
        "Subject: $subject_base64",
        "MIME-Version: 1.0",
        "Content-Type: text/html; charset=UTF-8",
        "Date: " . date('r'),
        "Message-ID: <" . uniqid() . "@" . ($_SERVER['SERVER_NAME'] ?? 'localhost') . ">"
    ];
    
    $data_body = implode("\r\n", $headers) . "\r\n\r\n" . $message;
    // Dot stuffing
    $data_body = preg_replace('/^\./m', '..', $data_body);
    
    // Send data stream
    $log("C: [sending email body]");
    fputs($socket, $data_body . "\r\n.\r\n");
    
    $response = $read($socket);
    $send($socket, "QUIT");
    fclose($socket);
    
    if (strpos($response, '250') === 0) {
        return [
            'success' => true,
            'message' => 'Email sent successfully via SMTP',
            'debug' => $debug
        ];
    } else {
        return [
            'success' => false,
            'message' => 'SMTP DATA execution failed: ' . trim($response),
            'debug' => $debug
        ];
    }
}

/**
 * Log email transmission details into the database.
 */
function logEmail($recipients, $subject, $message, $status, $error_message = null, $db = null) {
    if (!$db) {
        require_once __DIR__ . '/../config/database.php';
        $database = new Database();
        $db = $database->getConnection();
    }
    
    try {
        // Create table dynamically if it does not exist
        $create_sql = "CREATE TABLE IF NOT EXISTS email_logs (
            id INT AUTO_INCREMENT PRIMARY KEY,
            recipients TEXT NOT NULL,
            subject VARCHAR(255) NOT NULL,
            message TEXT NOT NULL,
            status VARCHAR(20) NOT NULL,
            error_message TEXT,
            sent_by INT,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        )";
        $db->exec($create_sql);
        
        $recipients_str = is_array($recipients) ? implode(', ', $recipients) : $recipients;
        $sent_by = $_SESSION['user_id'] ?? null;
        
        $insert_sql = "INSERT INTO email_logs (recipients, subject, message, status, error_message, sent_by) 
                       VALUES (:recipients, :subject, :message, :status, :error_message, :sent_by)";
        
        $stmt = $db->prepare($insert_sql);
        $stmt->execute([
            ':recipients' => $recipients_str,
            ':subject' => $subject,
            ':message' => $message,
            ':status' => $status,
            ':error_message' => $error_message,
            ':sent_by' => $sent_by
        ]);
        
        return true;
    } catch (PDOException $e) {
        error_log("logEmail database exception: " . $e->getMessage());
        return false;
    }
}
?>
