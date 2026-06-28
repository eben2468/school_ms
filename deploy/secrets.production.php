<?php
/**
 * PRODUCTION secrets for the School Management System.
 * --------------------------------------------------------------------------
 * 1. Edit the values below with your live database credentials.
 * 2. Upload/rename this file to:  config/secrets.php   (NOT inside deploy/)
 * 3. config/secrets.php is gitignored and is blocked from web access by
 *    .htaccess, so credentials never get served or committed.
 * --------------------------------------------------------------------------
 */

// --- Database connection ---------------------------------------------------
define('DB_HOST', 'localhost');                 // cPanel almost always uses 'localhost'
define('DB_NAME', 'CPANELUSER_school_ms');      // central DB (account-prefixed on cPanel)
define('DB_USER', 'CPANELUSER_schoolms');       // dedicated MySQL user (NOT root)
define('DB_PASS', 'REPLACE_WITH_STRONG_PASSWORD');

// --- Multi-tenant database prefix ------------------------------------------
// Tenant DBs are named <prefix><school-code>. Make this match your host's
// naming. On cPanel: '<cpaneluser>_school_ms_tenant_'.
define('DB_TENANT_PREFIX', 'CPANELUSER_school_ms_tenant_');

// --- Error display ---------------------------------------------------------
// MUST stay false in production (errors are logged, never shown to visitors).
define('APP_DEBUG', false);
