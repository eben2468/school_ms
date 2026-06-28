<?php
/**
 * Quick Fix: Redirect to comprehensive database setup
 * This fixes ALL missing tables including:
 * - grading_scales, conduct_records (Reports System)
 * - live_chat_rooms, live_chat_messages (Live Chat System)
 * - And more...
 */

session_start();

// Check if user is logged in and is admin
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['super_admin', 'school_admin'])) {
    die("Access denied. Please login as admin.");
}

// Redirect to the comprehensive setup script
header("Location: setup_all_missing_tables.php");
exit();
?>
