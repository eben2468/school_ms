<?php
/**
 * Deployment secrets template.
 * --------------------------------------------------------------------------
 * Copy this file to `config/secrets.php` and fill in real values for your
 * environment. `config/secrets.php` is gitignored so credentials never end up
 * in version control.
 *
 * SECURITY: Do NOT run the application as the MySQL `root` user in production.
 * Create a dedicated, least-privilege account instead, e.g.:
 *
 *   CREATE USER 'schoolms'@'localhost' IDENTIFIED BY 'a-strong-random-password';
 *   GRANT SELECT, INSERT, UPDATE, DELETE, CREATE, ALTER, INDEX, DROP, REFERENCES
 *     ON `school_ms`.* TO 'schoolms'@'localhost';
 *   -- repeat the GRANT for each tenant database, then:
 *   FLUSH PRIVILEGES;
 */

define('DB_HOST', 'localhost');
define('DB_NAME', 'school_ms');
define('DB_USER', 'schoolms');          // not 'root'
define('DB_PASS', 'change-me-strong');  // a strong, unique password

// Set to true only in development to display errors on screen.
define('APP_DEBUG', false);
