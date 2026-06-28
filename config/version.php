<?php
/**
 * Single source of truth for the application version.
 *
 * Update APP_VERSION here only — the footer, sidebar, and status API all read
 * from this constant, so they stay in sync automatically.
 */
if (!defined('APP_VERSION')) {
    define('APP_VERSION', '2.1.0');
}
