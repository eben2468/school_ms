<?php
/**
 * Login throttling / account lockout helper.
 * --------------------------------------------------------------------------
 * After a configurable number of failed password attempts, an identifier + IP
 * pair is temporarily locked out of the login page. The thresholds are managed
 * by super admins from Settings > System Settings and stored in school_settings
 * (login_max_attempts, login_lockout_duration).
 *
 * Attempts are tracked in the central directory database (the connection used
 * by the login lookup before any tenant switch), so a lockout applies globally
 * for that identifier regardless of which school it belongs to.
 */

if (!function_exists('ensureLoginAttemptsTable')) {
    function ensureLoginAttemptsTable($db) {
        try {
            $db->exec("CREATE TABLE IF NOT EXISTS login_attempts (
                id INT AUTO_INCREMENT PRIMARY KEY,
                identifier VARCHAR(255) NOT NULL,
                ip_address VARCHAR(45) NOT NULL,
                attempts INT NOT NULL DEFAULT 0,
                last_attempt_at DATETIME NOT NULL,
                locked_until DATETIME NULL,
                INDEX idx_identifier_ip (identifier, ip_address)
            )");
        } catch (PDOException $e) {
            error_log("login_attempts table ensure failed: " . $e->getMessage());
        }
    }
}

if (!function_exists('getLockoutConfig')) {
    /**
     * @return array { max_attempts:int, lockout_minutes:int }
     *         max_attempts <= 0 means the lockout feature is disabled.
     */
    function getLockoutConfig($db) {
        $config = ['max_attempts' => 5, 'lockout_minutes' => 15];
        try {
            $row = $db->query("SELECT login_max_attempts, login_lockout_duration FROM school_settings LIMIT 1")
                      ->fetch(PDO::FETCH_ASSOC);
            if ($row) {
                if (isset($row['login_max_attempts']) && $row['login_max_attempts'] !== null) {
                    $config['max_attempts'] = (int)$row['login_max_attempts'];
                }
                if (isset($row['login_lockout_duration']) && $row['login_lockout_duration'] !== null) {
                    $config['lockout_minutes'] = max(1, (int)$row['login_lockout_duration']);
                }
            }
        } catch (PDOException $e) {
            // Columns/table may not exist yet - fall back to defaults.
        }
        return $config;
    }
}

if (!function_exists('getLoginLockRemaining')) {
    /**
     * Seconds remaining on an active lock for this identifier + IP.
     * @return int 0 when not locked (or the feature is disabled)
     */
    function getLoginLockRemaining($db, $identifier, $ip) {
        $config = getLockoutConfig($db);
        if ($config['max_attempts'] <= 0) {
            return 0; // lockout disabled
        }
        try {
            $stmt = $db->prepare("SELECT locked_until FROM login_attempts
                                  WHERE identifier = :id AND ip_address = :ip
                                  ORDER BY id DESC LIMIT 1");
            $stmt->execute([':id' => $identifier, ':ip' => $ip]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($row && !empty($row['locked_until'])) {
                $remaining = strtotime($row['locked_until']) - time();
                return $remaining > 0 ? $remaining : 0;
            }
        } catch (PDOException $e) {
            // Table not present yet - treat as not locked.
        }
        return 0;
    }
}

if (!function_exists('recordFailedLogin')) {
    /**
     * Register a failed attempt and lock the identifier when the threshold is hit.
     * @return int seconds the identifier is now locked for (0 if not locked yet)
     */
    function recordFailedLogin($db, $identifier, $ip) {
        $config = getLockoutConfig($db);
        if ($config['max_attempts'] <= 0) {
            return 0; // lockout disabled
        }

        ensureLoginAttemptsTable($db);

        try {
            $stmt = $db->prepare("SELECT id, attempts, locked_until FROM login_attempts
                                  WHERE identifier = :id AND ip_address = :ip
                                  ORDER BY id DESC LIMIT 1");
            $stmt->execute([':id' => $identifier, ':ip' => $ip]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);

            $now = date('Y-m-d H:i:s');

            // Start a fresh window if there is no record, or the previous lock
            // has already expired; otherwise increment the running counter.
            if (!$row) {
                $attempts = 1;
            } elseif (!empty($row['locked_until']) && strtotime($row['locked_until']) <= time()) {
                $attempts = 1;
            } else {
                $attempts = (int)$row['attempts'] + 1;
            }

            $locked_until = null;
            $locked_secs = 0;
            if ($attempts >= $config['max_attempts']) {
                $locked_secs = $config['lockout_minutes'] * 60;
                $locked_until = date('Y-m-d H:i:s', time() + $locked_secs);
            }

            if ($row) {
                $upd = $db->prepare("UPDATE login_attempts
                                     SET attempts = :attempts, last_attempt_at = :now, locked_until = :locked
                                     WHERE id = :id");
                $upd->execute([
                    ':attempts' => $attempts,
                    ':now'      => $now,
                    ':locked'   => $locked_until,
                    ':id'       => $row['id'],
                ]);
            } else {
                $ins = $db->prepare("INSERT INTO login_attempts (identifier, ip_address, attempts, last_attempt_at, locked_until)
                                     VALUES (:id, :ip, :attempts, :now, :locked)");
                $ins->execute([
                    ':id'       => $identifier,
                    ':ip'       => $ip,
                    ':attempts' => $attempts,
                    ':now'      => $now,
                    ':locked'   => $locked_until,
                ]);
            }

            return $locked_secs;
        } catch (PDOException $e) {
            error_log("recordFailedLogin failed: " . $e->getMessage());
            return 0;
        }
    }
}

if (!function_exists('clearLoginAttempts')) {
    /**
     * Clear the failed-attempt history after a successful login.
     */
    function clearLoginAttempts($db, $identifier, $ip) {
        try {
            $stmt = $db->prepare("DELETE FROM login_attempts WHERE identifier = :id AND ip_address = :ip");
            $stmt->execute([':id' => $identifier, ':ip' => $ip]);
        } catch (PDOException $e) {
            // Table may not exist yet - nothing to clear.
        }
    }
}
