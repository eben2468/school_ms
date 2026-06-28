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
define('DB_NAME', 'school_ms');         // central directory DB. On cPanel this is
                                        // the account-prefixed name, e.g. 'myacct_school_ms'.
define('DB_USER', 'schoolms');          // not 'root'
define('DB_PASS', 'change-me-strong');  // a strong, unique password

// Prefix for per-school (tenant) databases created via Super Admin > Add School.
// Must match how your host names databases. On cPanel every DB is forced to start
// with your account name, so set this to '<cpaneluser>_school_ms_tenant_'. Locally
// it defaults to 'school_ms_tenant_'. Existing tenant DB names are stored in the
// central `schools.db_name` column and must line up with this prefix.
define('DB_TENANT_PREFIX', 'school_ms_tenant_');

// Set to true only in development to display errors on screen.
define('APP_DEBUG', false);
